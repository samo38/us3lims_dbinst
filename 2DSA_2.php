<?php
/*
 * 2DSA_2.php
 *
 * Final database update and submission for the 2DSA analysis
 *
 */
include_once 'checkinstance.php';
elogrs( __FILE__ );

if ( ($_SESSION['userlevel'] != 2) &&
     ($_SESSION['userlevel'] != 3) &&
     ($_SESSION['userlevel'] != 4) &&
     ($_SESSION['userlevel'] != 5) )    // only data analyst and up
{
  header('Location: index.php');
  if ( $is_cli ) {
    echo __FILE__ . " exiting 1\n";
  }
  exit();
} 

// Verify that job submission is ok now
include_once 'lib/motd.php';
if ( motd_isblocked() && ($_SESSION['userlevel'] < 4) )
{
  header("Location: index.php");
  if ( $is_cli ) {
    echo __FILE__ . " exiting 2\n";
  }
  exit();
}

// define( 'DEBUG', true );

include_once 'config.php';
include_once 'db.php';
include_once 'lib/utility.php';
include_once 'lib/payload_manager.php';
include_once 'lib/HPC_analysis.php';
include_once 'lib/file_writer.php';
include_once $class_dir . 'submit_local.php';
include_once $class_dir . 'submit_gfac.php';
include_once $class_dir . 'submit_airavata.php';

// Create the payload manager and restore the data
$payload = new Payload_2DSA( $_SESSION );
$payload->restore();
elogrs( __FILE__ . " after payload_restore" );
// Create the HPC analysis agent and file writer
$HPC       = new HPC_2DSA();
$file      = new File_2DSA();
$filenames = array();
$HPCAnalysisRequestID = 0;
$separate_datasets = $_SESSION['separate_datasets'];

$files_ok  = true;  // Let's also make sure there weren't any problems writing the files

if ( $separate_datasets > 0 )
{ // Multiple datasets and non-global: build composite jobs
  $dataset_count = $payload->get( 'datasetCount' );
  $job_params    = $payload->get( 'job_parameters' );
  $mgroup_count  = max( 1, $job_params['req_mgroupcount'] );
  $mc_iters      = max( 1, $job_params['mc_iterations'] );
  $reqds_count   = 50;              // Initial datasets per request
  if ( $mc_iters > 50 )
    $reqds_count   = 25;
  if ( $separate_datasets == 1 )
  {
    $reqds_count   = 1;
    $mgroup_count  = 1;
  }
  $groups        = (int)( $reqds_count / $mgroup_count );
  $groups        = max( 1, $groups );
  $reqds_count   = $mgroup_count * $groups;  // Multiple of PMGC
  $ds_remain     = $dataset_count;  // Remaining datasets
  $index         = 0;               // Input datasets index
  $kr            = 0;               // Output request index
$missit_msg = "<br/>ds_remain=" . $ds_remain;

  while ( $ds_remain > 0 )
  { // Loop to build HPC requests of composite jobs
    if ( ( $ds_remain - $reqds_count ) < $mgroup_count )
      $reqds_count   = $ds_remain;
    else
      $reqds_count   = min( $reqds_count, $ds_remain );

    $composite     = $payload->get_ds_range( $index, $reqds_count );
    $HPCAnalysisRequestID = $HPC->writeDB( $composite );
    $filenames[ $kr ] = $file->write( $composite, $HPCAnalysisRequestID );
    if ( $filenames[ $kr ] === false )
    {
$missit_msg .= "<br/>composite=" . $composite;
$missit_msg .= "<br/> kr=" . $kr;
$missit_msg .= "<br/> fnkr=" . $filenames[kr];
      $files_ok = false;
    }

    else
    { // Write the xml file content to the db
      $xml_content = mysqli_real_escape_string( $link, file_get_contents( $filenames[ $kr ] ) );
      $edit_filename = $composite['dataset'][0]['edit'];
      $experimentID  = $_SESSION['request'][$index]['experimentID'];

      $query  = "UPDATE HPCAnalysisRequest " .
                "SET requestXMLfile = '$xml_content', " .
                "experimentID = '$experimentID', " .
                "editXMLFilename = '$edit_filename' " .
                "WHERE HPCAnalysisRequestID = $HPCAnalysisRequestID ";
      mysqli_query( $link, $query )
            or die("Query failed : $query<br />\n" . mysqli_error($link));
    }

    $index        += $reqds_count;
    $ds_remain    -= $reqds_count;
    $kr++;
  }
}

else
{ // Multiple datasets and global
  $globalfit = $payload->get();
  $HPCAnalysisRequestID = $HPC->writeDB( $globalfit );
  $filenames[ 0 ] = $file->write( $globalfit, $HPCAnalysisRequestID );
  $missit_msg = '';
  if ( $filenames[ 0 ] === false )
  {
$missit_msg = "<br/>filenames[0]=" . $filenames[0];
    $files_ok = false;
  }

  else if ( $filenames[ 0 ] === '2DSA-IT-MISSING' )
  {
    $files_ok = false;
    $missit_msg = "<br/><b>Global Fit without all needed 2DSA-IT models</b/>";
  }

  else
  {
    // Write the xml file content to the db
    $xml_content = mysqli_real_escape_string( $link, file_get_contents( $filenames[ 0 ] ) );
    $edit_filename = $globalfit['dataset'][0]['edit'];

    $query  = "UPDATE HPCAnalysisRequest " .
              "SET requestXMLfile = '$xml_content', " .
              "editXMLFilename = '$edit_filename' " .
              "WHERE HPCAnalysisRequestID = $HPCAnalysisRequestID ";
    mysqli_query( $link, $query )
          or die("Query failed : $query<br />\n" . mysqli_error($link));
  }
}

if ( $files_ok )
{
  $output_msg = <<<HTML
  <pre>
  Thank you, your job was accepted and is currently processing. An
  email will be sent to {$_SESSION['submitter_email']} when the job is
  completed.

HTML;

  // EXEC COMMAND FOR TIGRE 
  if ( isset($_SESSION['cluster']) )
  {
    $cluster     = $_SESSION['cluster']['shortname'];
    unset( $_SESSION['cluster'] );

    // Currently we are supporting two submission methods.
    switch ( $cluster )
    {
       case 'jetstream-local' :
       case 'taito-local'     :
       case 'puhti-local'     :
       case 'demeler3-local'  :
       case 'chinook-local'   :
       case 'demeler9-local'  :
       case 'umontana-local'  :
       case 'us3iab-node0'    :
       case 'us3iab-node1'    :
       case 'us3iab-devel'    :
          $job = new submit_local();
          break;
       case 'stampede2' :
       case 'lonestar5' :
       case 'comet'     :
       case 'juwels'    : 
       case 'jetstream' :
       case 'bridges2'  :
       case 'expanse'   :
       case 'expanse-gamc' :
          $job = new submit_airavata();
          break;

       default         :
          $output_msg .= "<br /><span class='message'>Unsupported cluster $cluster!</span><br />\n";
          $filenames = array();
          break;
    }
   
    $save_cwd = getcwd();         // So we can come back to the current 
                                  // working directory later

    foreach ( $filenames as $filename )
    {
      chdir( dirname( $filename ) );

      $job-> clear();

      $job-> parse_input( basename( $filename ) );
      if ( ! DEBUG ) $job-> submit();
      $retval = $job->get_messages();

      if ( ! empty( $retval ) )
      {
        $output_msg .= "<br /><span class='message'>Message from the queue...</span><br />\n" .
                        print_r( $retval, true ) . " <br />\n";
      }
else {
$output_msg .= "<br /><span class='message'>Message from the queue...filename=$filename</span><br/>\n";
$output_msg .= "</pre>\n";
echo <<<HTML
<!-- Begin page content -->
<div id='content'>
 <h1 class="title">$page_title</h1>
 <!-- Place page content here -->
 $message
 <p>$output_msg</p>
</div>
HTML;
  if ( $is_cli ) {
    echo __FILE__ . " exiting 3\n";
  }
exit(0);
}
    }

    $job->close_transport();   // Will be dummy for newer classes
    chdir( $save_cwd );
  }
  $output_msg .= "</pre>\n";
}

else
{
  $output_msg = <<<HTML
  Thank you, there have been one or more problems writing the various files necessary
  for job submission. Please contact your system administrator.
  $missit_msg

HTML;

}

// Start displaying page
$page_title = '2DSA Analysis Submitted';
include 'header.php';

$message = ( isset( $message ) ) ? "<p class='message'>$message</p>" : "";
$show = $payload->show( $HPCAnalysisRequestID, $filenames );  // debugging info, if enabled

echo <<<HTML
<!-- Begin page content -->
<div id='content'>

  <h1 class="title">$page_title</h1>
  <!-- Place page content here -->

  $message
  <p>$output_msg</p>

  <p><a href="queue_setup_1.php">Submit another request</a></p>

  $show

</div>

HTML;

include 'footer.php';
if ( $is_cli ) {
  return;
}
exit();

?>

<?php

/**
 * This script will parse through all of our code base and generate documentation for any error
 * codes that it finds.
 *
 * This will work provided we keep the COEXException(message, code, [previous]) format.
 * 
 * 
 * TODO:  (Not really a todo, but) the biggest improvement this script could use is a refactor
 * to allow it to only step through all of the text of the source ONCE, as opposed to it currently
 * stepping through it TWICE. 
 *
 * @author gibssa
 */


require_once("../src/main/php/define/Global.php");
global $root_path, $conflictingErrors;


//************************************************************************************************
// Class and function definitions.
//************************************************************************************************

/**
 * Quick helper class to get status codes to post to wiki for documentation.
 *
 */
class StatusCodeToken {

    public $statusCode;
    public $meaning;
    public $message;
    public $lineNumber;

    public function __construct($statusCode, $meaning, $message, $lineNumber = null, $filename = null) {
        $this->statusCode = $statusCode;
        $this->meaning = $meaning;
        $this->message = $message;
        $this->lineNumber = $lineNumber;
        $this->filename = $filename;
    }
}

/**
 * Helper class just for this script.
 * @author gibssa
 *
 */
class FileObject {
    public $filename;
    public $filepath;
    public $source;
    
    public function __construct($filename, $filepath, $source) {
        $this->filename = $filename;
        $this->filepath = $filepath;
        $this->source = $source;
    }
}

/**
 * Helper class for the range
 * @param unknown_type $input
 * @return boolean
 */
class Range {
    public $rangeName;
    public $rangeStart;
    public $rangeEnd;    
    
    public function __construct($rangeName, $rangeStart, $rangeEnd) {
        $this->rangeName = $rangeName;
        $this->rangeStart = $rangeStart;
        $this->rangeEnd = $rangeEnd;
    }
}


function wordIsException($input) {

    global $exceptionNames;

    return in_array($input, $exceptionNames);
}

/**
 * Helper function to parse through all of our source code.
 * @param unknown_type $path
 * @param unknown_type $allOfOurSource
 * @param unknown_type $allFilenames
 * @throws Exception
 */
function getAllSource($path, &$allOfOurSource, &$allFilenames) {

    // Open path
    if (($directory = opendir($path)) === false) {
        echo 'ERROR on opening path $\n';
    }

    // Read contents of path
    while (($fname = readdir($directory)) !== false) {

        $newPath = $path . '/' . $fname;

        if (substr($fname, strlen($fname) - 4, 4) == '.php') {

            // Read the entire file
            if (($file_contents = file_get_contents($newPath)) === false) {
                throw new Exception("File failed to open", null);
            }
            $allFilenames[] = $fname;

            // Tokenize the contents of the file
            $allOfOurSource[] = new FileObject($fname, $newPath, token_get_all($file_contents));
            
            continue;
        }

        if (($fname == '.') || ($fname == '..') || ($fname == '.svn')) {
            continue;
        }
        else if (is_dir($newPath)) {
            getAllSource($newPath, $allOfOurSource, $allFilenames);
        }

    }
    closedir($directory);
}

/**
 * Step through our source once to find any classes called 'COEXException' or any classes
 * that extend 'COEXException'.
 * 
 * NEW:  Also discovers the definitions of what our INTERNAL/EXTERNAL ranges are.
 * 
 * @param unknown_type $allOfOurSource
 */
function parseForExceptionNames($allOfOurSource, &$internalRanges, &$externalRanges) {
    
    $exceptionFilenames = array();
    
    foreach ($allOfOurSource as $fileObject) {
    
        $fileSource = $fileObject->source;
    
        foreach ($fileSource as $index => $word) {
    
            if (($fileSource[$index][1] == 'class' && $fileSource[$index+2][1] == 'COEXException' &&
                    $fileSource[$index+4][1] == 'extends' && $fileSource[$index+6][1] == 'Exception') ||
                ($fileSource[$index][1] == 'class' && $fileSource[$index+4][1] == 'extends' && 
                        $fileSource[$index+6][1] == 'COEXException')) {
                $exceptionFilenames[] = $fileObject->filepath;
            }
            
            // Looking for instances of things like this:
            //         $internalRanges['Internal Business Logic Errors'] = range(1000, 1999);
            if ($fileSource[$index][1] == '$internalRanges' && $fileSource[$index+1] == '[' &&
                    $fileSource[$index+3] == ']') {
                $internalRanges[] = new Range(
                        substr($fileSource[$index+2][1], 1, -1),  // Strip off the apostrophes 
                        $fileSource[$index+9][1],
                        $fileSource[$index+12][1]);            
            }
            else if ($fileSource[$index][1] == '$externalRanges' && $fileSource[$index+1] == '[' &&
                    $fileSource[$index+3] == ']') {
                $externalRanges[] = new Range(
                        substr($fileSource[$index+2][1], 1, -1),  // Strip off the apostrophes 
                        $fileSource[$index+9][1],
                        $fileSource[$index+12][1]);
            }
        }
    }
    return $exceptionFilenames;
}


/**
 * Given the halfway filled status codes, parse through all of our source code and find
 * instances of where a message is given to it.  Fill in the "message" field with this.
 *
 * @param unknown_type $allOfOurSource
 * @param unknown_type $codes
 */
function insertMessages($allOfOurSource, &$codes) {
    global $conflictingErrors;
    $conflictingErrors = 0;
    
    foreach ($allOfOurSource as $fileObject) {
        
        $fileSource = $fileObject->source;
        
        foreach ($fileSource as $index => $word) {
            $i = 0;
            $message = "";

            if ($fileSource[$index][1] == 'throw' && $fileSource[$index+2][1] == 'new' &&
                    wordIsException($fileSource[$index+4][1])) {
                
                $errorType = $fileSource[$index+4][1];
                $lineNumber = $fileSource[$index][2];

                // Construct the message to inject
                while ($fileSource[$index+$i] !== ',') {
                    $targetToken = $fileSource[$index+$i][1];

                    if ($targetToken !== 'throw' && $targetToken !== ' ' && $targetToken !== 'new'
                            && !wordIsException($targetToken)) {

                        // If there's a PHP variable in the message, replace it with the word 'value'
                        if (substr($targetToken, 0, 1) == '$') {
                            $message .= 'value';
                        }
                        // remove any object references, leave variables as (value)
                        else if ($fileSource[$index+$i-1][1] == '->' || 
                                $targetToken == '->') {
                            // do nothing
                        }
                        // remove all indexing, also in the effort to leave variables as (value)
                        else if (substr($fileSource[$index+$i][1], 0, 2) == '[\'') {
                            $message .= preg_replace('/\[.*\]/i', '', $fileSource[$index+$i][1]);
                        }
                        else {
                            $message .= $fileSource[$index+$i][1];
                        }
                    }
                    $i++;

                    // Check to see if this isn't a well-formed exception
                    if ($i >= 50) {
                        //echo "iterated past where the comma to end the message should have been<br />";
                        break;
                    }
                }
                
                /*
                 * Last bit of message formatting done in these blocks.
                 */
                $message = trim($message);
                
                // Swap []'s with ()'s
                $message = preg_replace('/\[/', '(', $message);
                $message = preg_replace('/\]/', ')', $message);
                
                // Wrap all instances of 'value' with '(value)' 
                $message = preg_replace('/value/', '(value)', $message);
                // HACK: Unwrap any unwanted instances of '((value))'
                $message = preg_replace('/\(\(value\)\)/', '(value)', $message);
                // HACK: Clean up any unwanted instances of '()'
                $message = preg_replace('/\(\)/', '', $message);
                

                // Once we have the message, iterate past everything else,
                // up to the name (the MEANING) of the exception.
                while (!wordIsException($fileSource[$index+$i][1])) {
                    $i++;

                    // Check to see if this isn't a well-formed exception
                    if ($i >= 50) {
                        //echo "iterated past where the exception code should have been<br />";
                        break;
                    }

                }

                // Skip over the "::" token
                $i += 2;

                // Inject the message into the corresponding StatusCode object.
                $key = $fileSource[$index+$i][1];
                
                // If that particular code's message has already been set, and they're *not* equal, then
                // tell the user of this script.
                if (isset($codes[$key]->message) && $codes[$key]->message != $message) {
                    $conflictingErrors++;
                    echo "ERROR: Inconsistent messages for error " . $codes[$key]->statusCode . 
                          " (" . $errorType . ")<br />  
                          &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Existing message:&nbsp;&nbsp;&nbsp;"
                           . $codes[$key]->message . "  {In " . $codes[$key]->filename . 
                           ", line " . $codes[$key]->lineNumber . "}<br />
                          &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Conflicting message: $message  {In " . 
                          $fileObject->filename . ", line " . $lineNumber . "}<br /><br />";
                    continue;
                }
                
                $codes[$key]->message = $message;
                $codes[$key]->lineNumber = $lineNumber;
                $codes[$key]->filename = $fileObject->filename;
            }
        }
    }

    return $codes;
}

// Comparator used to sort the codes by statusCodes (used in usort later)
function cmp($a, $b) {
    if ($a->statusCode == $b->statusCode) {
        return 0;
    }
    return ($a->statusCode < $b->statusCode) ? -1 : 1;
}


// Function to check if an item is in range
function inRange($statusCode, $ranges) {
    foreach ($ranges as $range) {
        if ($statusCode >= $range->rangeStart && $statusCode <= $range->rangeEnd)
            return true;
    }
    return false;
}


//************************************************************************************************
// START flow of control
//************************************************************************************************

$allOfOurSource = array();
// Grab tokenized text representations for all of our source.
getAllSource($root_path, $allOfOurSource, $allFilenames);


$internalRanges = array();
$externalRanges = array();

// First iteration through the source for exception names, and also range definitions
$exceptionFilenames = parseForExceptionNames($allOfOurSource, $internalRanges, $externalRanges);

// Make an array of exceptionNames, based on the filenames (truncate off the path and extension)
foreach ($exceptionFilenames as $filename) {
    $exploded = explode("/", $filename);

    $exceptionName = substr(array_pop($exploded), 0, -4);
    $exceptionNames[] = $exceptionName;
}

$count = 0;
$codes = array();

// Read the exception files to construct all status codes (without the messages just yet)
foreach ($exceptionFilenames as $filename) {
    
    // Read the entire file
    if (($file_contents = file_get_contents($filename)) === false) {
        throw new Exception("File failed to open: $filename", null);
    }

    $tokens = token_get_all($file_contents);

    // Put each instance of "public static $" into a new status code
    foreach ($tokens as $index => $token) {
        if (is_array($token) && $tokens[$index][1] == 'public' && $tokens[$index+2][1] == 'static' &&
                substr($tokens[$index+4][1], 0, 1) == '$' && $tokens[$index+6] == '=' &&
                is_numeric($tokens[$index+8][1])) {
            $count++;
            $codes[$tokens[$index+4][1]] = new StatusCodeToken($tokens[$index+8][1], $tokens[$index+4][1], null);
        }
    }
}

// Second iteration through the text of the source.
// Inject messages into the StatusCode objects, by parsing all of our source.
$codes = insertMessages($allOfOurSource, $codes);

/* This sort will erase the key structure of having the key be the meaning.
 * That is, it changes the array to make the keys be numbers [0, (n-1)]. */
usort($codes, "cmp");

// Output the finished ranges.
echo "h5. Internal ranges:<br />";
echo "|| Range Start || Range End || Type ||<br />";
foreach ($internalRanges as $range) {
    echo ' | ' . $range->rangeStart . ' | ' . $range->rangeEnd . ' | ' . $range->rangeName . " |<br />";
}
echo "<br /><br />";
echo "h5. External ranges:<br />";
echo "|| Range Start || Range End || Type ||<br />";
foreach ($externalRanges as $range) {
    echo ' | ' . $range->rangeStart . ' | ' . $range->rangeEnd . ' | ' . $range->rangeName . " |<br />";
}
echo "<br /><br /><br />";


// HACK currently used to let me straight-up copy paste to the Wiki  ;-)
echo "h4. Overview<br /><br />";
echo "These are the codes that are used for processing retailer signup, or returning errors through the API.<br /><br />";

// Output the finished codes, currently formatted to support the Wiki.
// Internal codes:
echo 'h4.  Internal codes<br />';
foreach ($codes as $code) {
    if (inRange($code->statusCode, $internalRanges)) {
        if (isset($code->statusCode) && isset($code->meaning) && isset($code->message)) {
            echo '|' . $code->statusCode.'|'.$code->message.'|<br />';
        }
    }
}
// External codes:
echo '<br /><br />h4.  External codes<br />';
foreach ($codes as $code) {
    if (inRange($code->statusCode, $externalRanges)) {
        if (isset($code->statusCode) && isset($code->meaning) && isset($code->message)) {
            echo '|' . $code->statusCode.'|'.$code->message.'|<br />';
        }
    }
}

echo "<br /><br />Finished with a count of $count status codes.<br />";
echo "FOUND $conflictingErrors CONFLICTING ERROR MESSAGES.<br /><br />";

// //   IF YOU'D LIKE:  You can use this variable to see which files this read from.
//            echo "Used the following files: ";
//            var_dump($allFilenames);
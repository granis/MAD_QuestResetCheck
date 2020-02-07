#!/usr/bin/php
<?php
$iniFile = __DIR__ . "/config.ini";
$tmpFile = __DIR__ . "/lastcheck.dat";
$lastSaved = (@filemtime($tmpFile) ? @filemtime($tmpFile) : time()-(3600*24));
$pokestopsFile = __DIR__ . "/pokestops.ini";
$pokestopGuids = file($pokestopsFile);
$dbHost = "localhost";
$dbName = "pokemon";
$dbUser = "pokemon";
$dbPassword = "";
$dbPort = null;
$dbSource = "";
$discord = "";
$db;
$dbPokestopsData = array();

parse_mad_config_for_db($iniFile);
connect_to_db($db);

$populatedOK = populate_quest_data($db);
if(!$populatedOK) {
    exit("One or more of the pokestops is missing quest-data, so we dont do anything yet.\n");
}

if(date("Y-m-d") != date("Y-m-d", $lastSaved)) {
    save_quest_data($db);
    exit("It's a new day since our last saved data! So we dont do any comparison. But we save the new data.\n");
}

$savedPokestopData = get_saved_quest_data($db);
if(count($savedPokestopData) != count($dbPokestopsData)) {
    save_quest_data($db);
    exit("Not same amount of pokestops in saved-data and in db-data, maybe you changed pokestops.ini. Save the new data.\n");
}

$diff = false;
$diffStops = array();
$mismatch = false; //if pokestops.ini was changed, but remained same number of stops, we have a mismatch
foreach($dbPokestopsData as $dbStop) {
    if(array_key_exists($dbStop->pokestop_id, $savedPokestopData)) {
        if($dbStop != $savedPokestopData[$dbStop->pokestop_id]) {
            echo "A quest on pokestop {$dbStop->pokestop_id} was changed!\n";
            $diffStops[$dbStop->pokestop_id] = $dbStop;
            $diff = true;
        }
    } else {
        echo "Pokestop with ID {$dbStop->pokestop_id} was not found in the saved file.\n";
        $mismatch = true; //it's a mismatch.. not a diff
    }
}


if($mismatch) {
    echo "A mismatch between saved pokestops-id and pokestops.ini id was found. Save the new data.\n";
    save_quest_data($db);
    exit(0);
}

if($diff) {
    $msg = "A diff between saved pokestop-data and database pokestop-data was found. Quests may have been reset!\n";
    echo $msg;
    send_discord($msg);
    save_quest_data($db);
    exit(0);
}

$enoughTimePassed = (time()-$lastSaved) > (3600*1);
//check if its time to reset quests in db again, so we can check if the new quests match them
if(!$diff && $enoughTimePassed) {
    save_quest_data($db); //update the timestamp.. (and data)
    foreach($dbPokestopsData as $pokestop) {
        echo "Deleting quest data for pokestop {$pokestop->pokestop_id}\n";
        delete_pokestop_quest_info($db, $pokestop->pokestop_id);
    }
    $msg = "We didnt find any mismatch, or a diff. And time have passed, so we reset the quests in the database to check again.\n";
    send_discord($msg);
    exit($msg);
}

$lastcheck = time()-$lastSaved;
exit("No action taken. Last save was {$lastcheck}s ago.\n");



function get_saved_quest_data(&$db) {
    GLOBAL $tmpFile;
    return unserialize(file_get_contents($tmpFile));
}

function save_quest_data(&$db) {
    GLOBAL $dbPokestopsData, $tmpFile, $lastSaved;
    file_put_contents($tmpFile, serialize($dbPokestopsData));
    $lastSaved = time();
}

/**
 * populates the $dbPokestopsData..
 * @return true if all quest-data could be found
 * @return false if some stop is missing quest-data
 */
function populate_quest_data(&$db) {
    GLOBAL $pokestopGuids, $dbPokestopsData;
    $missing = false;
    foreach($pokestopGuids as $id) {
        $id = trim($id);
        $pokestop = get_pokestop_quest_info($db, $id);
        if(empty($pokestop->quest_type)) {
            $missing = true;
        }
        $dbPokestopsData[$id] = $pokestop;
    }
    return !$missing; // :D
}

function delete_pokestop_quest_info(&$db, &$id) {
    $sql = "DELETE FROM trs_quest WHERE GUID=:id LIMIT 1";
    $statement = $db->prepare($sql);
    $statement->execute(array(':id' => $id));
    return $statement;
}

function get_pokestop_quest_info(&$db, &$id) {
    $sql = "SELECT 
            p.pokestop_id,
            p.name,
            q.quest_type,
            q.quest_stardust,
            q.quest_pokemon_id,
            q.quest_reward_type,
            q.quest_item_id,
            q.quest_item_amount,
            q.quest_target,
            q.quest_condition,
            q.quest_reward,
            q.quest_template
            FROM `pokestop` p LEFT JOIN trs_quest q ON p.pokestop_id=q.GUID WHERE p.pokestop_id=:id";
    $statement = $db->prepare($sql);
    $statement->execute(array(':id' => $id));
    $pokestopObj = $statement->fetch(PDO::FETCH_OBJ);
    return $pokestopObj;
}



function connect_to_db(&$_db) {
    GLOBAL $dbUser, $dbPassword, $dbSource;
    try {
        $_db = new PDO($dbSource, $dbUser, $dbPassword);
    } catch (Exception $ex) {
        print_r($ex);
        exit("Error connecting to database. Verify credentials and/or host.\n");
    }
}

function parse_mad_config_for_db(&$path) {
    GLOBAL $dbHost, $dbUser, $dbPassword, $dbName, $dbPort, $dbSource, $discord;
    $txt = file_get_contents($path);
    //grab all lines starting with db* and extract any text found on other side of : except whitespace
    //trim removes any "' that might be around the value
    $trimPattern = "\"\'";
    preg_match_all('/^(\S+): (\S+)/m', $txt, $matches, PREG_SET_ORDER);
    foreach($matches as $match) {
        switch($match[1]) {
            case "dbip":
                $dbHost = trim($match[2], $trimPattern);
                break;
            case "dbusername":
                $dbUser = trim($match[2], $trimPattern);
                break;
            case "dbpassword":
                $dbPassword = trim($match[2], $trimPattern);
                break;
            case "dbname":
                $dbName = trim($match[2], $trimPattern);
                break;
            case "dbport":
                $dbPort = trim($match[2], $trimPattern);
                break;
            case "discordhookurl":
                $discord = trim($match[2], $trimPattern);
                break;
        }
    }

    $dbSource = "mysql:host={$dbHost};dbname={$dbName};charset=UTF8";
    if(!empty($dbPort)) {
        $dbSource .= ";port={$dbPort}";
    }
}

function send_discord($msg) {
    GLOBAL $discord;

    $ch = curl_init($discord);
    curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
    curl_setopt( $ch, CURLOPT_POST, 1);
    curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode(array("content" => $msg)));
    curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt( $ch, CURLOPT_HEADER, 0);
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
    $out = curl_exec($ch);
}

?>

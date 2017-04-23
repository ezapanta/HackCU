<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header("Content-Type: application/json; charset=UTF-8");

$servername = "localhost";
$database = "elpalgnt_hackcu_db";
$username = "elpalgnt_hackcu";
$password = "+0)CqGVI~=i7";

// Create connection
$conn = new mysqli($servername, $username, $password, $database);
// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


function clamp_down($val) {

	return floor($val*100)/100;

	$rounded = round($val, 3);
	$rounded *= 1000;
	$mod = abs(fmod($rounded,10)); // get last value
	$inc = .005;
	if ($mod < 5) {
		return round($val,2);
	}else{
		return round($val,2) - $inc;
	}
}

function clamp_up($val) {

	return ceil($val*100)/100;

	$rounded = abs(round($val, 3));
	$rounded *= 1000;
	$mod = abs(fmod($rounded,10)); // get last value
	$inc = .005;
	if ($mod <= 5) {
		return round($val,2) + $inc;
	}else{
		return round($val,2);
	}
}

if(isset($_REQUEST['a'])) {

	$a = $_REQUEST['a'];

	if($a == "get_regions" &&
			isset($_REQUEST['lat']) &&
			isset($_REQUEST['lon']) &&
			isset($_REQUEST['user_id'])    ) {

		// ==== Get request params

		$lat = $_REQUEST['lat'];
		$lon = $_REQUEST['lon'];
		$user_id = $_REQUEST['user_id'];

		$ret = array();

		// ==== Get current location box

		$lat_start = clamp_down($lat);
		$lat_end = clamp_up($lat);
		$lon_start = clamp_down($lon);
		$lon_end = clamp_up($lon);

		// ==== Get user data
		$sql = "SELECT * FROM users WHERE id = $user_id;";
		$result = $conn->query($sql);

		if (mysqli_num_rows($result) == 0) {
			die('{"error":"No users with that ID found"}');
		}

		$row = $result->fetch_assoc();
		$user_team_id = (int)$row['team_id'];


		// ==== get region id OR make new region if in new region

		$sql = "SELECT * FROM regions WHERE ABS(lat_start - $lat_start) < .005 AND ABS(lat_end - $lat_end) < .005 AND ABS(lon_start - $lon_start) < .005 AND ABS(lon_end - $lon_end) < .005;"; // SQL floats are STUPID!!!
		$result = $conn->query($sql);

		if (mysqli_num_rows($result) == 0) { // Make a new Region
			$sql = "INSERT INTO regions (lat_start, lat_end, lon_start, lon_end) VALUES ($lat_start, $lat_end, $lon_start, $lon_end);";
			$conn->query($sql);
			$current_region_id = $conn->insert_id;

			$sql = "SELECT * FROM teams;";
			$result = $conn->query($sql);
			while($row = $result->fetch_assoc()) {
				$team_id = $row['id'];
				$new_score = ((int)$row['id'] == $user_team_id) ? 1 : 0;
				$sql2 = "INSERT INTO region_team_score (region_id, team_id, score) VALUES ($current_region_id, $team_id, $new_score);";
				$conn->query($sql2);
			}
		}else{
			$row = $result->fetch_assoc();
			$current_region_id = (int)$row['id'];
		}

		$ret['region_id'] = $current_region_id;

		// ==== Update user with current location

		$sql = "SELECT id FROM users WHERE id = $user_id;";
		$result = $conn->query($sql);

		$sql = "UPDATE users SET in_region = $current_region_id WHERE id = $user_id;";
		$conn->query($sql);

		// ==== Return full list of regions

		$ret['regions'] = array();

		$sql = "SELECT * FROM regions;";
		$result = $conn->query($sql);
		$i = 0;
		while($row = $result->fetch_assoc()) {

			$region_id = (int)$row['id'];

	        $ret['regions'][$i]['id'] = $region_id;
	        $ret['regions'][$i]['coordinates'][0]=array(
	        	'lat'=>(float)$row['lat_start'], 'lon'=>(float)$row['lon_start']
	        );
	        $ret['regions'][$i]['coordinates'][1]=array(
	        	'lat'=>(float)$row['lat_start'], 'lon'=>(float)$row['lon_end']
	        );
	        $ret['regions'][$i]['coordinates'][2]=array(
	        	'lat'=>(float)$row['lat_end'], 'lon'=>(float)$row['lon_end']
	        );
	        $ret['regions'][$i]['coordinates'][3]=array(
	        	'lat'=>(float)$row['lat_end'], 'lon'=>(float)$row['lon_start']
	        );

	        $sql2 = "SELECT * FROM region_team_score WHERE region_id = $region_id;";;
			$result2 = $conn->query($sql2);

			$max = -1;
			$max_team = -1;

			while($row2 = $result2->fetch_assoc()) {

				if((int)$row2['score'] > $max) {
					$max_team = (int)$row2['team_id'];
					$max = (int)$row2['score'];
				}
			}

			$ret['regions'][$i]['team_winning'] = $max_team;

	        $i++;
	    }

		echo json_encode($ret);

	} elseif($a == "get_battle" &&
			isset($_REQUEST['region_id'])  ) {
		// ==== Get request params

		$region = $_REQUEST['region_id'];

		$sql = "SELECT name, region_id, score, COUNT(CASE users.team_id WHEN teams.id THEN 1 ELSE NULL END) AS count
            FROM region_team_score AS RTS
            JOIN users ON users.in_region = RTS.region_id
            RIGHT JOIN teams ON teams.id = RTS.team_id
            WHERE region_id = $region
            GROUP BY RTS.team_id";

		$result = $conn->query($sql);
		$i = 0;
    while($row = $result->fetch_assoc()){
            #  die(var_dump($row));
  	        $ret['team_data'][$i]['team_name'] = $row['name'];
            $ret['team_data'][$i]['count'] = $row['count'];
  	        $ret['team_data'][$i]['score'] = $row['score'];

            $i++;

    }
    echo json_encode($ret);

	} elseif($a == "set_battle" &&
			isset($_REQUEST['region_id']) &&
      isset($_REQUEST['user_id'])  ) {

        $region = $_REQUEST['region_id'];
        $user = $_REQUEST['user_id'];

        $sql = "UPDATE region_team_score
                SET score = score + 1
                WHERE region_id = $region AND
                team_id = (
                  SELECT team_id
                  FROM users
                  WHERE id = $user
                )";

        $result = $conn->query($sql);

        echo '{"success":"true"}';

	} elseif($a == "new_id" ) {

        $sql = "SELECT * FROM users ORDER BY id DESC";

        $result = $conn->query($sql);

        $row = $result->fetch_assoc();

        if(mysqli_num_rows($result) == 0 || $row['team_id'] == 2) {
        	$new_team_id = 1;
        }else{
        	$new_team_id = 2;
        }

        $sql = "INSERT INTO users (team_id, in_region) VALUES ($new_team_id, -1);";

        $conn->query($sql);
		$new_user_id = $conn->insert_id;

		$new_team = ($new_team_id == 1) ? "Red" : "Blue";

        echo '{"id":'.$new_user_id.',"team_id":'.$new_team_id.',"team":"'.$new_team.'"}';
	} else {
		echo "Invalid URL sent, not enough or wrong parameters";
	}


} else {
	echo "Invalid Request. Must have an action and parameters";
}












$conn->close();

?>

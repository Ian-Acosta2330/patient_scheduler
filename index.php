<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="./favicon.ico">
    <title>Patient Scheduling</title>
</head>

<style>
    table{
        padding-top: 50px;
        margin: auto;
        text-align: center;
    }
    thead{
        font-size: 1.5em;
    }
    tbody{
        font-size: 1.5=2em;
    }
    .tableContainer{
        margin-left: auto;
        margin-right: auto;
        text-align: center;
    }
    #adder {
        padding-bottom: 50px;
    }

</style>

<script>
    if ( window.history.replaceState ) {
        window.history.replaceState( null, null, window.location.href );
    }
</script>

<body>
    <h1 style="display:flex; justify-content:center;">Patients</h1><hr style="width:80%;">
    
    <?php

    //  --- JSON SETUP FUNCTIONS ---

    //given text file to a more convenient json
    function fromTextToJSON(){
        $filename = "patientlist-full.txt";

        $file = fopen($filename, "r");
        
        $patients = [];

        $name="";
        $address="";
        $coord1=0;
        $coord2=0; 
        $day="";
        $month="";
        $year="";

        if($file){
            $value = 0;
            while(!feof($file)){
                $line = fgets($file);
                if ($line==""||$line==" "||$line=="\n") {
                    break;
                }
                $token = strtok($line,","); //separate by comma
                while($token !== false){ // get values from file
                    if($value==0){
                        $name = trim($token);
                    }
                    if($value==1){
                        $address=trim($token);
                    }
                    if($value==2){
                        $coord1 = ord(trim($token)[0])-64;
                        $coord2=(int)trim($token)[1];
                    }
                    if($value==3){ //get separate dates
                        $dateInt = 0;
                        $dateToken = strtok($token,"/");
                        while($dateInt < 3){
                            if($dateInt == 0) {
                                $day = trim($dateToken);
                            }
                            if($dateInt == 1) {
                                $month = trim($dateToken);
                            }
                            if($dateInt == 2) {
                                $year = trim($dateToken);
                            }
                            $dateInt = $dateInt + 1;
                            $dateToken = strtok("/");
                        }
                    }
                    $value=$value+1;
                    $token = strtok(",");
                }
                // add onto json data
                $patients[] =[ 
                    "name"=>$name,
                    "address"=>$address,
                    "coordinate1"=>$coord1,
                    "coordinate2"=>$coord2,
                    "day"=>$day,
                    "month"=>$month,
                    "year"=>$year,
                ];
                $value = 0;
            }
            $userJson = json_encode($patients, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            file_put_contents("patients.json",$userJson);
            fclose($file);
            
            return $patients;

        } else {
            echo "could not read file";
        }

    }

    //from json to dynamic html table format
    function populate($f){
        $userJson = file_get_contents($f);
        $patients = json_decode($userJson, true);
        // populate as a table
        echo"<div class='tableContainer'>";
        echo "<table cellpadding='8' cellspacing='1'>";
        echo "<thead>".
        "<tr>"."<th scope='col'>Name</th>".
        "<th scope='col'>Address</th>".
        "<th scope='col'>Coordinates</th>".
        "<th scope='col'>Date</th>".
        "</tr>"."</thead>";
        echo "<tbody>";
        foreach ($patients as $patient) {
            echo "<tr>".
            "<th scope='row'>".htmlspecialchars($patient['name'])."</th>".
            "<td>". htmlspecialchars($patient['address'])."</td>".
            "<td>".toLetter($patient['coordinate1'])." ".$patient['coordinate2'] ."</td>".
            "<td>".$patient['day'] . "/" . $patient['month']."/" .$patient['year'] ."</td>".
            "<td>
                <form method='post' action=''>
                    <label>New?
                        <input type='checkbox' name='is_new' value='1'>
                    </label>
                    <input type='hidden' name='action' value='add_schedule'>
                    <input type='hidden' name='name' value='" . htmlspecialchars($patient['name']) . "'>
                    <input type='hidden' name='address' value='" . htmlspecialchars($patient['address']) . "'>
                    <input type='hidden' name='coordinate1' value='" . $patient['coordinate1'] . "'>
                    <input type='hidden' name='coordinate2' value='" . $patient['coordinate2'] . "'>
                    <input type='hidden' name='day' value='" . $patient['day'] . "'>
                    <input type='hidden' name='month' value='" . $patient['month'] . "'>
                    <input type='hidden' name='year' value='" . $patient['year'] . "'>
                    <input type='time' name='start'>
                    <input type='time' name='end'>
                    <input type='submit' value='Add To Schedule'>
                </form>
            </td>";

            echo "</tr>";
        }
        echo "</div>";
    }

    //------------------------------------------
    
    //   --- HELPER METHODS ---

    //calculate drive from coords
    function calcDrive($letterRep1, $col1, $letterRep2,$col2) {
        $num1 = abs( $letterRep2 - $letterRep1 );
        $num2 = abs( $col2 - $col1 );
        return  2 * ( pow($num1,2) + pow($num2,2) ) ;
    } 

    //visually see coordinates, but keep json numerical
    function toLetter($letter){
        return chr($letter+64);
    }

    //------------------------------------------

    //  --- LOGIC METHODS ---

    function addPatient($action,$f){
        if($action=='add_pat'){
            $data = file_get_contents($f);
            $patients= $data ? json_decode($data, true) : [];

            $birthday=htmlspecialchars($_POST["addBirthday"]);
            list($day,$month,$year) = explode("/",$birthday);

            $patient = [
                "name"       => htmlspecialchars($_POST["addName"]),
                "address"    => htmlspecialchars($_POST["addAddress"]),
                "coordinate1"=> ord(strtoupper($_POST["addCoords"])[0]) - 64,
                "coordinate2"=> substr($_POST["addCoords"], 1),
                "day"        => $day,
                "month"      => $month,
                "year"       => $year
            ];
            $patients[] = $patient;
            file_put_contents($f, json_encode($patients, JSON_PRETTY_PRINT));
        } 

    }

    function addToSchedule($action){
        $filename = 'schedule.json';
        if($action=='add_schedule'){
            if(file_exists($filename)){
                $data=file_get_contents($filename);
                $content = $data ? json_decode($data,true):[];
            } else{
                $content = [];
            }
    
            $entry = [
                "name"=>htmlspecialchars($_POST['name']),
                "address"=>htmlspecialchars($_POST['address']),
                "coordinate1"=>htmlspecialchars($_POST['coordinate1']),
                "coordinate2"=>htmlspecialchars($_POST['coordinate2']),
                "day"=>htmlspecialchars($_POST['day']),
                "month"=>htmlspecialchars(($_POST['month'])),
                "year"=>htmlspecialchars(($_POST['year'])),
                "availableStart"=>htmlspecialchars(($_POST['start'])),
                "availableEnd"=>htmlspecialchars(($_POST['end'])),
                "new"=>htmlspecialchars($_POST['is_new']),
            ];

            $content[]= $entry;

            file_put_contents($filename, json_encode($content, JSON_PRETTY_PRINT));       
        } 
    }

    function populateSchedule($f){
        $userJson = file_get_contents($f);
        $patients = json_decode($userJson, true);
        // populate as a table
        echo"<div class='tableContainer'>";
        echo "<table cellpadding='8' cellspacing='1'>";
        echo "<caption>Draft Schedule</caption>";
        echo "<thead>".
        "<tr>"."<th scope='col'>Name</th>".
        "<th scope='col'>Address</th>".
        "<th scope='col'>Coordinates</th>".
        "<th scope='col'>Date</th>".
        "<th scope='col'>New Patient?</th>".
        "<th scope='col'>Times Available</th>".
        "</tr>"."</thead>";
        echo "<tbody>";
        foreach ($patients as $patient) {
            echo "<tr>".
            "<th scope='row'>".htmlspecialchars($patient['name'])."</th>".
            "<td>". htmlspecialchars($patient['address'])."</td>".
            "<td>".toLetter($patient['coordinate1'])." ".$patient['coordinate2'] ."</td>".
            "<td>".$patient['day'] . "/" . $patient['month']."/" .$patient['year'] ."</td>".
            "<td>".boolval($patient['new'])."</td>".
            "<td>".$patient['availableStart']."-". $patient['availableEnd']."</td>";
            echo "</tr>";
        }
        echo "</div>";
    }

    //MAIN -- Function Calls
    if (!file_exists("patients.json")){
        fromTextToJSON();
    }

    $action = $_POST['action'] ?? '';
    
    addPatient($action, "./patients.json");
    populate("./patients.json");
    addToSchedule($action);
    if(file_exists('./schedule.json')){
        populateSchedule('./schedule.json');
    }

    ?>

<hr>
    <!-- cool form to add a patient -->
    <form id="adder" method="post" action="">

        <h2>Add a patient</h2>

        <input type="hidden" name="action" value="add_pat">

        <label for="textbox">Name: </label>
        <input type="text" id="addName" name="addName" required>
        
        <label for="textbox">Address: </label>
        <input type="text" id="addAddress" name="addAddress">
        
        <label for="textbox">Map Coordinates: </label>
        <input type="text" id="addCoords" name="addCoords">
        
        <label for="textbox">Birth Date: </label>
        <input type="text" id="addBirthday" name="addBirthday">
        <!-- <label for="checkbox">New?</label>
        <input type="checkbox" id="newPat" name="checkbox"> -->
        <input type="submit" value="Add">
    </form>
    <hr>

</body>
</html>
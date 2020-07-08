<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . "/externallib.php");
require_once($CFG->libdir.'/authlib.php');
require_once($CFG->dirroot . '/user/lib.php');

/**
 * AHEA User authentication plugin.
 */
class auth_plugin_ahea extends auth_plugin_base  {

    public function pre_loginpage_hook() {

        $this->log(__FUNCTION__ . ' enter');
        $this->loginpage_hook();
        $this->log(__FUNCTION__ . ' exit');
    }

    /**
     * A debug function, dumps to the php log
     *
     */
    private function log($msg) {
        error_log('auth_ahea: ' . $msg);
    }

    /**
      * Method to process login call from Master Application.
      * Parameters: None
      * returns None 
      */
    public function loginpage_hook() {

        global $CFG, $DB, $USER, $SESSION;

        // If user param is present then its an SSO call else direct login call to moodle.
        if (isset($_GET["user"])){
            $opts = [
                "http" => [
                        "method" => "GET"
                        ]
                    ];
            
            $context = stream_context_create($opts);
            $user_token = $_GET["user"];

            $user_detail = $this->get_user_details($user_token);
            $username = $user_detail->institutionEmail;
    
            $user = $DB->get_record('user', array('username' => $username, 'deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id));
            if($user){
                $user_detail->id = $user->id;
                $user_detail->firstname = $user_detail->firstName;
                $user_detail->lastname = $user_detail->lastName;
                user_update_user ($user_detail);
                complete_user_login($user);
                $this->set_cookie_and_redirect($username);                
            } else{
                $new_user = $this->create_user($user_detail);
                complete_user_login($new_user);
                $this->set_cookie_and_redirect($username);                
            }
        }
        
    }

    /**
      * Method to get a user details using API call from Master Application.
      * Parameters:
      * $user_token : token id obtained in initial login call from master application 
      * returns $user_detail, stdClass Object of the user 
      */
    public function get_user_details($user_token){
        
        $master_url = 'http://23.99.141.44:3000/getUserDetails?user=';
        $user_response = file_get_contents($master_url.$user_token, false, $context);

        $user_response = json_decode($user_response);
        $user_detail = $user_response->user;

        return $user_detail;
    }

    /**
      * Method to create a user in moodle with details obtained from API call.
      * Mark the user as confirmed so the user does not need to confirm using email.
      * Parameters:
      * $user_detail : stdClass object 
      * returns $new_user, stdClass Object of the newly created user 
      */
    public function create_user($user_detail) {
        global $DB, $CFG;        

        unset($user['ip']);
        $user['confirmed'] = 1;
        $user['mnethostid'] = $CFG->mnet_localhost_id;
        $user['username'] = $user_detail->institutionEmail;
        $user['email'] = $user_detail->institutionEmail;
        $user['firstname'] = $user_detail->firstName;
        $user['lastname'] = $user_detail->lastName;
        $user['password'] = firstname.'*Moodle12';

        $requiredfieds = ['username', 'email', 'firstname', 'lastname'];
        $missingfields = [];
        foreach ($requiredfieds as $requiredfied) {
            if (empty($user[$requiredfied])) {
                $missingfields[] = $requiredfied;
            }
        }
        if (!empty($missingfields)) {
            throw new invalid_parameter_exception('Unable to create user, missing value(s): ' . implode(',', $missingfields));
        }

        if ($DB->record_exists('user', array('username' => $user['username'], 'mnethostid' => $CFG->mnet_localhost_id))) {
            throw new invalid_parameter_exception('Username already exists: '.$user['username']);
        }
        if (!validate_email($user['email'])) {
            throw new invalid_parameter_exception('Email address is invalid: '.$user['email']);
        } else if (empty($CFG->allowaccountssameemail) &&
            $DB->record_exists('user', array('email' => $user['email'], 'mnethostid' => $user['mnethostid']))) {
            throw new invalid_parameter_exception('Email address already exists: '.$user['email']);
        }

        $userid = user_create_user($user);
        $new_user = $DB->get_record('user', ['id' => $userid]);
        return $new_user;
    }

    
    public function user_login($username, $password) {
        return false;
    }


    /**
      * Method to set moodle cookie and redirect user to dashboard after successfull login.
      * Parameters:
      * $username : user name of the user 
      * returns None 
      */
    public function set_cookie_and_redirect($username){
        $USER->loggedin = true;
        $USER->site = $CFG->wwwroot;
        set_moodle_cookie($username);
        $urltogo = $CFG->wwwroot.'/my';
        redirect($urltogo);
    }

}

?>
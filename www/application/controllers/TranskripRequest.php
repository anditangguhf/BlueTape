<?php

defined('BASEPATH') OR exit('No direct script access allowed');

class TranskripRequest extends CI_Controller {

    public function __construct() {
        parent::__construct();
        try {
            $this->Auth_model->checkModuleAllowed(get_class());
        } catch (Exception $ex) {
            $this->session->set_flashdata('error', $ex->getMessage());
            header('Location: /');
        }
        $this->load->library('bluetape');
        $this->load->model('Transkrip_model');
        $this->load->database();
    }

    public function index() {
        // Retrieve logged in user data
        $userInfo = $this->Auth_model->getUserInfo();
        // Retrieve requests for this user
        $requests = $this->Transkrip_model->requestsBy($userInfo['email']);
        $forbiddenTypes = $this->Transkrip_model->requestTypesForbidden($requests);
        foreach ($requests as &$request) {
            if ($request->answer === NULL) {
                $request->status = 'TUNGGU';
                $request->labelClass = 'secondary';
            } else if ($request->answer === 'printed') {
                $request->status = 'TERCETAK';
                $request->labelClass = 'success';
            } else if ($request->answer === 'rejected') {
                $request->status = 'DITOLAK';
                $request->labelClass = 'alert';
            }
            $request->requestDateString = $this->bluetape->dbDateTimeToReadableDate($request->requestDateTime);
            $request->requestByName = $this->bluetape->getName($request->requestByEmail);
            $request->answeredDateString = $this->bluetape->dbDateTimeToReadableDate($request->answeredDateTime);
        }
        unset($request);

        $this->load->view('TranskripRequest/main', array(
            'currentModule' => get_class(),
            'requestByEmail' => $userInfo['email'],
            'requestByNPM' => $this->bluetape->getNPM($userInfo['email'], '-'),
            'requestByName' => $userInfo['name'],
            'requests' => $requests,
            'forbiddenTypes' => $forbiddenTypes
        ));
    }

    public function add() {
        try {
            if ($this->input->server('REQUEST_METHOD') == 'POST'){
                date_default_timezone_set("Asia/Jakarta");
                $userInfo = $this->Auth_model->getUserInfo();
                $requests = $this->Transkrip_model->requestsBy($userInfo['email']);
                $forbiddenTypes = $this->Transkrip_model->requestTypesForbidden($requests);
                if (is_string($forbiddenTypes)) {
                    throw new Exception($forbiddenTypes);
                }
                $requestType = htmlspecialchars($this->input->post('requestType'));
                $requestType = $this->input->post('requestType');
                if (in_array($requestType, $forbiddenTypes)) {
                    throw new Exception("Tidak bisa, karena transkrip $requestType sudah pernah dicetak di semester ini.");
                }

                /*
                    #   CodeIgniter automatically escaping all inserted values to produce safer queries.
                        We can exploit this by using basic CI query function without escaping chars so we can do sql injection.

                    #   htmlspecialchars() is a php function that sanitizes inputted data, changes symbols to html entities.
                        This is useful to prevent script injection, so it needs to be removed so script injection can be done.

                    #   CodeIgniter uses settings in config.php to prevent CSRF Attack. CodeIgniter sets csrf_protection to TRUE,
                        sets csrf_token and csrf_cookie_name so CI can do CSRF token checking.
                        This needs to be disabled / reverted back to default (where protection is set to FALSE) so we can do CSRF Attack.

                    Reference: https://www.tutorialspoint.com/codeigniter/codeigniter_security.htm
                */

                /* Original insert function */
                $this->db->insert('Transkrip', array(
                    'requestByEmail' => $userInfo['email'],
                    'requestDateTime' => strftime('%Y-%m-%d %H:%M:%S'),
                    'requestType' => $requestType,
                    'requestUsage' => htmlspecialchars($this->input->post('requestUsage'))
                ));

                /*
                 * ===== SQL Injection Exploit =====
                 * DB Insert function without sanitizer, using multi_query so sql injection can be done
                 */
                // $dbcon = mysqli_connect('localhost', 'root', '', 'kamin_bluetape');
                // $query = "INSERT INTO transkrip (requestByEmail, requestDateTime, requestType, requestUsage) VALUES ('".$userInfo['email']."', '".strftime('%Y-%m-%d %H:%M:%S')."', '$requestType', '".htmlspecialchars($this->input->post('requestUsage'))."')";
                // mysqli_multi_query($dbcon, $query);

                /*
                 * ===== Script Injection Exploit =====
                 * DB Insert function without sanitizer for inputted data  (removed htmlspecialchars)
                 * We can still use the original insert function as the one that prevents script injection is the htmlspecialchars
                 * insert this script: <script>window.location.href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"</script>
                 */
                // $this->db->insert('Transkrip', array(
                //     'requestByEmail' => $userInfo['email'],
                //     'requestDateTime' => strftime('%Y-%m-%d %H:%M:%S'),
                //     'requestType' => $requestType,
                //     'requestUsage' => $this->input->post('requestUsage') // removed htmlspecialchars()
                // ));

                /*
                 * ===== CSRF Attack =====
                 * To enable CSRF Attack we need to disable CSRF Protection to FALSE, and remove any CSRF Token checking on TranskripRequest page.
                 * We can still use the original insert function as it doesn't affect the attack.
                 */
                // $this->db->insert('Transkrip', array(
                //     'requestByEmail' => $userInfo['email'],
                //     'requestDateTime' => strftime('%Y-%m-%d %H:%M:%S'),
                //     'requestType' => $requestType,
                //     'requestUsage' => htmlspecialchars($this->input->post('requestUsage'))
                // ));

                $this->session->set_flashdata('info', 'Permintaan cetak transkrip sudah dikirim. Silahkan cek statusnya secara berkala di situs ini.');

                $this->load->model('Email_model');
                $recipients = $this->config->item('roles')['tu.ftis'];
                if (is_array($recipients)) {
                    foreach ($recipients as $email) {
                        $requestByName = $this->bluetape->getName($userInfo['email']);
                        $subject = "Permohonan Transkrip dari $requestByName";
                        $message = $this->load->view('TranskripRequest/email', array(
                            'name' => $this->bluetape->getName($email),
                            'requestByName' => $requestByName
                        ), TRUE);
                        $this->Email_model->send_email($email, $subject, $message);
                    }
                }
            } else {
                throw new Exception("Can't call method from GET request!");
            }
        } catch (Exception $e) {
            $this->session->set_flashdata('error', $e->getMessage());
        }
        header('Location: /TranskripRequest');
    }

}

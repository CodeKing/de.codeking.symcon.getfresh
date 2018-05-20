<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/helpers/autoload.php');

/**
 * Class GetFresh
 * Driver to GetFresh API (inofficial)
 *
 * @version     1.1
 * @category    Symcon
 * @package     de.codeking.symcon.getfresh
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKing/de.codeking.symcon.getfresh
 */
class GetFresh extends Module
{
    use InstanceHelper;

    private $email;
    private $password;
    private $token;

    private $position_offset = 0;

    public $data = [];

    protected $archive_mappings = [ // archive: 0 = default, 1 = counter
        'Base Price' => 0,
        'Price per kWh' => 0,
        'Current Price' => 0,
        'Meter Reading' => 1,
        'Power' => 0
    ];

    protected $profile_mappings = [
        'Base Price' => 'Price',
        'Price per kWh' => 'Price',
        'Meter Reading' => 'kWh',
        'Power' => 'Watt',
        'Power L1' => 'Watt',
        'Power L2' => 'Watt',
        'Power L3' => 'Watt',
        'Provider' => '~String'
    ];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register public properties
        $this->RegisterPropertyString('email', 'user@email.com');
        $this->RegisterPropertyString('password', '');
        $this->RegisterPropertyInteger('interval', 60); // in seconds

        // register timer
        $this->RegisterTimer('UpdateData', 0, $this->_getPrefix() . '_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('UpdateTariffData', 60 * 1000, $this->_getPrefix() . '_UpdateTariff($_IPS[\'TARGET\']);');
    }

    /**
     * execute, when kernel is ready
     */
    protected function onKernelReady()
    {
        // update timer
        $this->SetTimerInterval('UpdateData', $this->ReadPropertyInteger('interval') * 1000);

        // Update tariff details
        $this->UpdateTariff();

        // Update data
        $this->Update();
    }

    /**
     * Read config
     */
    private function ReadConfig()
    {
        // get settings
        $this->email = $this->ReadPropertyString('email');
        $this->password = $this->ReadPropertyString('password');
        $this->token = $this->GetBuffer('token');

        // return if service or internet connection is not available
        if (!Sys_Ping('getfresh.energy', 1000)) {
            $this->_log('GetFresh', 'Error: api or internet connection not available!');
            exit(-1);
        }

        // check if email and password are provided
        if (!$this->email || !$this->password) {
            return false;
        }

        // read access token
        if (!$this->token) {
            $this->token = $this->GetBuffer('token');
        }

        // login if no valid token was provided
        if (!$this->token) {
            $this->Login();
        }

        // simple error handling
        if (!$this->token) {
            $this->SetStatus(201);
            $this->_log('GetFresh', 'Error: The email address or password of your account is invalid!');
            exit(-1);
        }

        // everything looks ok, start
        $this->SetStatus(102);
        return true;
    }

    /**
     * read & update tariff data
     */
    public function UpdateTariff()
    {
        // read config
        $this->ReadConfig();

        // get tariff provider name
        if ($profile = $this->Api('profile')) {
            $this->data['Provider'] = $profile['brandName'];
        }

        // get tariff
        if ($tariff = $this->Api('consumptionCurrentMonth')) {
            $this->data['Base Price'] = $tariff['monthlyBasePrice']['value'];
            $this->data['Price per kWh'] = $tariff['unitPrice']['value'];
        }

        // log data
        $this->_log('GetFresh Tariff Data', json_encode($this->data));

        // save data
        $this->position_offset = 0;
        $this->SaveData();
    }

    /**
     * read & update consumption data
     */
    public function Update()
    {
        // read config
        $this->ReadConfig();

        // get current readings
        if ($currentReadings = $this->Api('currentReadings')) {
            $cnt = count($currentReadings['readings']) - 1;
            $currentReading = $currentReadings['readings'][$cnt];

            $this->data['Meter Reading'] = $currentReading['energyReading'];
            $this->data['Power'] = $currentReading['power'];
            $this->data['Power L1'] = $currentReading['powerPhase1'];
            $this->data['Power L2'] = $currentReading['powerPhase2'];
            $this->data['Power L3'] = $currentReading['powerPhase3'];
        }

        // log data
        $this->_log('GetFresh Data', json_encode($this->data));

        // save data
        $this->position_offset = 10;
        $this->SaveData();
    }

    /**
     * save data to variables
     */
    private function SaveData()
    {
        // loop data and save to variable
        $position = $this->position_offset;
        foreach ($this->data AS $key => $value) {
            $this->CreateVariableByIdentifier([
                'parent_id' => $this->InstanceID,
                'name' => $key,
                'value' => $value,
                'position' => $position
            ]);
            $position++;
        }

        // reset data
        $this->data = [];
    }

    /**
     * basic api to getfresh (inofficial)
     * @param string $request
     * @return array
     */
    public function Api($request = 'currentReadings')
    {
        // build url
        $url = 'https://www.getfresh.energy/links';

        // default data
        $data = [];

        // curl options
        $curlOptions = [
            CURLOPT_TIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
                'Connection: keep-alive',
                'Accept-Encoding: gzip',
                'User-Agent: okhttp/3.2.0'
            ]
        ];

        // call api
        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOptions);
        $links = curl_exec($ch);

        // get links
        if ($links = json_decode($links, true)) {

            #var_dump($links);
            #exit;

            $links = [
                'currentReadings' => $links['_links']['currentReadings']['href'],
                'consumption' => $links['_links']['consumption']['href'],
                'profile' => $links['_links']['profile']['href'],
                'consumptionCurrentMonth' => $links['_links']['consumptionCurrentMonth']['href']
            ];

            if (isset($links[$request])) {
                curl_setopt($ch, CURLOPT_URL, $links[$request]);

                $result = curl_exec($ch);
                $data = json_decode($result, true);
            }
        }

        // close curl
        curl_close($ch);

        // return data
        return $data;
    }

    /**
     * Login to getfresh
     */
    public function Login()
    {
        $this->_log('GetFresh', sprintf('Logging in to account of %s...', $this->email));

        // login url
        $url = 'https://www.getfresh.energy/oauth/token';

        // curl options
        $curlOptions = [
            CURLOPT_TIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'password',
                'username' => $this->email,
                'password' => $this->password
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept-Encoding: gzip',
                'Connection: keep-alive',
                'User-Agent: okhttp/3.2.0'
            ],
            CURLOPT_USERPWD => 'fresh-webclient:'
        ];

        // login
        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOptions);
        $result = curl_exec($ch);
        curl_close($ch);

        // extract token
        $json = json_decode($result, true);
        $this->token = isset($json['access_token']) ? $json['access_token'] : false;

        // save valid token
        if ($this->token) {
            $this->SetStatus(102);
            $this->SetBuffer('token', $this->token);
        } // simple error handling
        else {
            $this->SetStatus(201);
            $this->_log('GetFresh', 'Error: The email address or password of your account is invalid!');
            exit(-1);
        }

        return true;
    }

    /**
     * create custom variable profile
     * @param string $profile_id
     * @param string $name
     */
    protected function CreateCustomVariableProfile(string $profile_id, string $name)
    {
        switch ($name):
            case 'Watt':
                IPS_CreateVariableProfile($profile_id, 2); // float
                IPS_SetVariableProfileDigits($profile_id, 0); // 0 decimals
                IPS_SetVariableProfileText($profile_id, '', ' W'); // Watt
                IPS_SetVariableProfileIcon($profile_id, 'Electricity');
                break;
            case 'kWh':
                IPS_CreateVariableProfile($profile_id, 2); // float
                IPS_SetVariableProfileDigits($profile_id, 2); // 2 decimals
                IPS_SetVariableProfileText($profile_id, '', ' kWh'); // Watt
                IPS_SetVariableProfileIcon($profile_id, 'Electricity');
                break;
            case 'Price':
                IPS_CreateVariableProfile($profile_id, 2); // float
                IPS_SetVariableProfileDigits($profile_id, 4); // 4 decimals
                IPS_SetVariableProfileText($profile_id, '', ' €'); // currency symbol
                IPS_SetVariableProfileIcon($profile_id, 'Euro');
                break;
        endswitch;
    }
}
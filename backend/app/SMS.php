<?php

namespace App;
class SMS
{

    public $smsUrl = 'https://api.taqnyat.sa/v1/messages';
    public $bearerToken = "Bearer 1e798ba819ca85b7847612e85afe709d";
    public $senderName = 'RATCO';


    function sendConfermationSMSToClient($message, $recipient)
    {

        $recipients = [$recipient];

        $curl = curl_init();

        $fields = ["sender" => $this->senderName, "recipients" => $recipients, "body" => $message];
        $f = json_encode($fields);
        $headers = ["Content-Type" => "application/json", "Authorization: " => $this->bearerToken];
        $h = json_encode($headers);
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->smsUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $f,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: ' . $this->bearerToken,
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        $rrr = json_decode($response, true);

        return $rrr;
    }

}

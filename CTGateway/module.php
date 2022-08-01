<?php
// Klassendefinition
class CTGateway extends IPSModule {

    // Überschreibt die interne IPS_Create($id) Funktion
    public function Create() {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->RegisterPropertyString('ctUrl', 'https://');
        $this->RegisterPropertyString('ctToken', '');

        $this->RegisterTimer("Update", 600000, 'CTG_UpdateRoomUsage('. $this->InstanceID . ');');
        $this->RegisterAttributeInteger("LastUpdated", 0); // Attribute: interne Variable
    }

    // Überschreibt die intere IPS_ApplyChanges($id) Funktion
    public function ApplyChanges()
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        $masterData = $this->GetMasterData();
    }

    /**
     * Die folgenden Funktionen stehen automatisch zur Verfügung, wenn das Modul über die "Module Control" eingefügt wurden.
     * Die Funktionen werden, mit dem selbst eingerichteten Prefix, in PHP und JSON-RPC wiefolgt zur Verfügung gestellt:
     *
     * CT_UpdateVersion($id);
     *
     */
    public function MyOwnFunction() {
        // Selbsterstellter Code

    }

    function MakeRequestCurl(string $method, string $url) {
        $error = false;
        $ch = curl_init($url);
        if ($ch === false) { // fehlgeschlagen
            return false;
        } else { // erfolgreich, curl handle
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/47.0.2526.111 Safari/537.36");;
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20); //timeout in seconds
            $data = curl_exec($ch);
            if (curl_errno($ch)) { // curl Fehler aufgetreten
                echo curl_error($ch);
                $error = true;
            }
            curl_close($ch);
            if (!$error) { // curl read erfolgreich, Datei schreiben
                return $data;
            }
            return false;
        }
    }

    private function GetMasterData() {
        $validConfig = (strlen($this->ReadPropertyString('ctUrl')) > 8) &&
            (strlen($this->ReadPropertyString('ctToken')) > 20);

        if ($validConfig) {
            // MasterData von ChurchTools holen
            try {
                $data = $this->MakeRequestCurl('GET', $this->ReadPropertyString('ctUrl') .
                    '/api/resource/masterdata?login_token=' .
                    $this->ReadPropertyString('ctToken'));
                if ($data === false) {
                    $data = '[]';
                }
                $masterData = json_decode($data, true);
            } catch (Exception $exception) {
                $masterData = [];
            }

            if (isset($masterData['data']) && isset($masterData['data']['resourceTypes'])) {
                $this->SetStatus(102); // Instanz ist aktiv
                return $masterData['data'];
            }
        }
        $this->SetStatus(201);
        return [];
    }

    public function GetConfigurationForm()
    {
        $masterData = $this->GetMasterData();

        $availableDevices = [];

        if (isset($masterData['resourceTypes'])) {
            $resourceTypes = [];
            foreach ($masterData['resourceTypes'] as $resourceType) {
                $resourceTypes[$resourceType['id']] = $resourceType;
            }
            foreach ($masterData['resources'] as $resource) {
                $availableDevices[] = [
                    'name' => 'ChurchTools ' . $resourceTypes[$resource['resourceTypeId']]['name'] . ' ' . $resource['name'],
                    'roomID' => '' . $resource['id'],
                    'location' => $resource['location'] !== null ? $resource['location'] : '',
                    'instanceID' => 0, // noch nicht erstellt
                    'create' => [
                        'moduleID' => '{24696BB4-BA33-42CA-86A4-67FD7E4AED89}',
                        'configuration' => [
                            'roomID' => '' . $resource['id']
                        ]
                    ]
                ];
                if ($resource['location'] !== null) {
                    $availableDevices[count($availableDevices)-1]['create']['location'] = [$resource['location']];
                }
            }
        } else {
            $this->SetStatus(201);
        }

        $index = 0;
        $roomIDToIndex = [];
        $roomIDToName = [];
        foreach ($availableDevices as $device) {
            $roomIDToIndex[$device['roomID']] = $index;
            $roomIDToName[$device['roomID']] = $device['name'];
            $index += 1;
        }

        foreach (IPS_GetInstanceListByModuleID('{24696BB4-BA33-42CA-86A4-67FD7E4AED89}') as $instanceID) {
            $roomID = IPS_GetProperty($instanceID, 'roomID');
            if (isset($roomIDToIndex[''.$roomID])) {
                $availableDevices[$roomIDToIndex[$roomID]]['instanceID'] = $instanceID;
                $availableDevices[$roomIDToIndex[$roomID]]['name'] = IPS_GetName($instanceID) .
                    ($roomIDToName[$roomID] !== IPS_GetName($instanceID) ? ' (in CT: ' . $roomIDToName[$roomID] . ')' : '');
            } else {
                $availableDevices[] = [
                    'name' => IPS_GetName($instanceID),
                    'roomID' => $roomID,
                    'instanceID' => $instanceID
                ];
            }
        }
        $values = $availableDevices;

        $jsonForm = [
            'elements' => [
                [
                    'type' => 'ValidationTextBox',
                    'name'=> 'ctUrl',
                    'caption' => 'ChurchTools URL'
                ],
                [
                    'type' => 'PasswordTextBox',
                    'name'=> 'ctToken',
                    'caption' => 'ChurchTools Login Token'
                ]
            ],
            'actions' => [
                [
                    'type' => 'Configurator',
                    'columns' => [
                        [
                            'name' => 'name',
                            'caption' => 'Room name',
                            'width' => 'auto'
                        ],
                        [
                            'name' => 'location',
                            'caption' => 'Location',
                            'width' => '150px'
                        ],
                        [
                            'name' => 'roomID',
                            'caption' => 'Room ID',
                            'width' => '150px'
                        ]
                    ],
                    'values' => $values
                ],
                [
                    'type' => 'Label',
                    'name' => 'InfoLabel',
                    'caption' => 'New room bookings are automatically fetched every 10 minutes. You can force fetching with the button below.',
                    'visible' => isset($masterData['resourceTypes'])
                ],
                [
                    'type' => 'Label',
                    'name' => 'LastUpdated',
                    'caption' => '',
                    'visible' => isset($masterData['resourceTypes'])
                ],
                [
                    'type' => 'Button',
                    'caption' => 'Update bookings now',
                    'onClick' => 'CTG_UpdateRoomUsage($id);',
                    'visible' => isset($masterData['resourceTypes'])
                ]
            ],
            'status' => [
                [
                    'code' => 201,
                    'icon' => 'error',
                    'caption' => 'Incomplete or wrong URL/token'
                ]
            ]
        ];
        $updateTime = $this->ReadAttributeInteger("LastUpdated");
        if ($updateTime === 0) {
            $jsonForm['actions'][2]['caption'] = $this->Translate('Last updated') . ": -";
        } else {
            $jsonForm['actions'][2]['caption'] = $this->Translate('Last updated') . ": " . date('d.m.Y H:i:s', $updateTime);
        }
        return json_encode($jsonForm);
    }

    public function UpdateRoomUsage() {
        $validConfig = (strlen($this->ReadPropertyString('ctUrl')) > 8) &&
            (strlen($this->ReadPropertyString('ctToken')) > 20);

        if (!$validConfig) {
            return;
        }

        IPS_LogMessage("CTGateway", 'Fetching room usage...');

        $resourceIds = [];
        $bookingForResource = [];
        foreach (IPS_GetInstanceListByModuleID('{24696BB4-BA33-42CA-86A4-67FD7E4AED89}') as $instanceID) {
            $roomID = IPS_GetProperty($instanceID, 'roomID');
            $resourceIds[] = 'resource_ids%5B%5D=' . $roomID;
            $bookingForResource['Booking' . $roomID] = [
                'resourceId' => $roomID,
                'caption' => '',
                'statusId' => 0,
                'includingRequests' => false,
                'startDate' => '',
                'endDate' => '',
            ];
            $bookingForResource['BookingOrRequest' . $roomID] = [
                'resourceId' => $roomID,
                'caption' => '',
                'statusId' => 0,
                'includingRequests' => true,
                'startDate' => '',
                'endDate' => '',
            ];
        }

        if (count($resourceIds) === 0) {
            return;
        }
        $now = new DateTime();
        $fromDate = $now->format('Y-m-d');
        $tomorrow = new DateTime('now +1 day');
        $toDate = $tomorrow->format('Y-m-d');

        $url = $this->ReadPropertyString('ctUrl') . '/api/bookings?' .
            join('&', $resourceIds) . '&from=' . $fromDate . '&to=' . $toDate .
            '&status_ids%5B%5D=1&status_ids%5B%5D=2&login_token=' . $this->ReadPropertyString('ctToken');

        try {
            $data = $this->MakeRequestCurl('GET', $url);
            if ($data === false) {
                $data = '[]';
            }
            $result = json_decode($data, true);
        } catch (Exception $exception) {
            $result = [];
        }

        if (isset($result['data'])) {
            $this->SetStatus(102); // Instanz ist aktiv

            if (is_array($result['data'])) {
                foreach ($result['data'] as $booking) {
                    $resourceId = $booking['base']['resource']['id'];
                    $startDate = new DateTime($booking['calculated']['startDate']);
                    $endDate = new DateTime($booking['calculated']['endDate']);
                    if ($booking['base']['allDay'] == true) {
                        $endDate->add(new DateInterval('P1D'));
                    }
                    $now = new DateTime();

                    // we send both: the first booking - and the first booking OR request.
                    // Then the CTRoomUsage can decide what to use
                    $currentBooking = $bookingForResource['Booking' . $resourceId];
                    if (($booking['base']['statusId'] === 2) && ($endDate > $now) && (
                            ($currentBooking['startDate'] == '') ||
                            ($startDate < new DateTime($currentBooking['startDate']))
                        )) {
                        $bookingForResource['Booking' . $resourceId] = [
                            'resourceId' => $resourceId,
                            'caption' => $booking['base']['caption'],
                            'includingRequests' => false,
                            'statusId' => $booking['base']['statusId'],
                            'startDate' => gmdate('Y-m-d\TH:i:s\Z', $startDate->format('U')),
                            'endDate' => gmdate('Y-m-d\TH:i:s\Z', $endDate->format('U')),
                        ];
                    }
                    $currentBookingOrRequest = $bookingForResource['BookingOrRequest' . $resourceId];
                    if (($endDate > $now) && (
                            ($currentBookingOrRequest['startDate'] == '') ||
                            ($startDate < new DateTime($currentBookingOrRequest['startDate']))
                        )) {
                        $bookingForResource['BookingOrRequest' . $resourceId] = [
                            'resourceId' => $resourceId,
                            'caption' => $booking['base']['caption'],
                            'includingRequests' => true,
                            'statusId' => $booking['base']['statusId'],
                            'startDate' => gmdate('Y-m-d\TH:i:s\Z', $startDate->format('U')),
                            'endDate' => gmdate('Y-m-d\TH:i:s\Z', $endDate->format('U')),
                        ];
                    }
                }
                foreach ($bookingForResource as $res => $booking) {
                    $json = json_encode([
                        'DataID' => "{80F27E23-9209-411F-B531-AF913960759C}",
                        'Buffer' => $booking
                    ]);
                    IPS_LogMessage("CTGateway", print_r($json, true));
                    $this->SendDataToChildren($json);
                }
                $updateTime = time();
                $this->WriteAttributeInteger("LastUpdated", $updateTime);
                $this->UpdateFormField("LastUpdated", "caption", $this->Translate("Last updated") . ": " . date('d.m.Y H:i:s', $updateTime));
            }
        } else {
            $this->SetStatus(201);
        }
    }
}

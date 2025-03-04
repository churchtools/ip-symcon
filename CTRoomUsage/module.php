<?php
// Klassendefinition
class CTRoomUsage extends IPSModule {

    // Überschreibt die interne IPS_Create($id) Funktion
    public function Create() {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->ConnectParent("{726CCC58-96A5-4ECC-9597-65D0AFCD0E44}");

        $this->RegisterPropertyInteger('roomID', 0); // Property: kann über die Einstellungen/Formular der Instanz gesetzt werden, wird auch für create verwendet
        $this->RegisterPropertyBoolean('treatRequestsAsBooked', false);
        $this->RegisterPropertyInteger('preheatingMinutes', 0);
        $this->RegisterPropertyInteger('stopHeatingEarlyMinutes', 0);

        $this->RegisterVariableBoolean('roomInUse', $this->Translate('room in use')); // Variable: wird als Variable angezeigt und kann von außen abgerufen werden
        $this->RegisterVariableBoolean('roomInUseWithPreheating', $this->Translate('heating'));
        $this->RegisterVariableString('nextBookingTitle', $this->Translate('Next booking title'));
        $this->RegisterVariableString('nextBookingStartDate', $this->Translate('Next booking start date'));
        $this->RegisterVariableString('nextBookingEndDate', $this->Translate('Next booking end date'));
        $this->RegisterVariableString('nextBookingStatus', $this->Translate('Next booking status'));
        $this->SetValue('roomInUse', false);
        $this->SetValue('roomInUseWithPreheating', false);

        $this->RegisterTimer("Update", 10000, 'CTR_UpdateUsage('. $this->InstanceID . ');');

        $this->RegisterAttributeString('bookings', '[]');
    }

    // Überschreibt die intere IPS_ApplyChanges($id) Funktion
    public function ApplyChanges()
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        $this->SetReceiveDataFilter('.*"resourceId":' . $this->ReadPropertyInteger('roomID') . ',.*|^$');
        $this->UpdateUsage();
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

    // Empfangene Daten vom Parent (RX Paket) vom Typ Simpel
    public function ReceiveData($JSONString)
    {
    $data = json_decode($JSONString, true);
        
    // Überprüfen, ob die empfangenen Daten leer sind
    if (empty($data['Buffer'])) {
        // Wenn ja, gespeicherte Buchungen zurücksetzen
        $this->WriteAttributeString('bookings', '[]');
    } else {
        // Ansonsten die empfangenen Buchungen speichern
        $this->WriteAttributeString('bookings', json_encode($data['Buffer']));
    }

    // Nutzung aktualisieren
    $this->UpdateUsage();
    }

    public function UpdateUsage() {

        $bookings = json_decode($this->ReadAttributeString('bookings'), true);

        $nextBooking = null;
        $roomInUse = false;
        $roomInUseWithPreheating = false;
        $now = new DateTime();
        $includingRequests = $this->ReadPropertyBoolean('treatRequestsAsBooked');

        foreach (array_reverse($bookings) as $booking) {
            if (($booking['statusId'] != 0) && ($includingRequests || ($booking['statusId'] == 2))) {
                $startDate = new DateTime($booking['startDate']);
                $preheatDate = new DateTime($booking['startDate']);
                $preheatDate = $preheatDate->sub(new DateInterval('PT' . $this->ReadPropertyInteger('preheatingMinutes') . 'M'));
                $endDate = new DateTime($booking['endDate']);
                $stopHeatingDate = new DateTime($booking['endDate']);
                $stopHeatingDate = $stopHeatingDate->sub(new DateInterval('PT' . $this->ReadPropertyInteger('stopHeatingEarlyMinutes') . 'M'));
                if ($now < $stopHeatingDate) {
                    $nextBooking = $booking;
                }
                if (($now >= $startDate) && ($now < $endDate)) {
                    $roomInUse = true;
                }
                if (($now >= $preheatDate) && ($now < $stopHeatingDate)) {
                    $roomInUseWithPreheating = true;
                }
            }
        }
        if ($nextBooking !== null) {
            $startDate = new DateTime($nextBooking['startDate']);
            $startDateFormatted = date($this->Translate('Y-m-d H:i:s'), $startDate->format('U'));
            $endDate = new DateTime($nextBooking['endDate']);
            $endDateFormatted = date($this->Translate('Y-m-d H:i:s'), $endDate->format('U'));
            $this->SetValue('nextBookingTitle', $nextBooking['caption']);
            $this->SetValue('nextBookingStartDate', $startDateFormatted);
            $this->SetValue('nextBookingEndDate', $endDateFormatted);
            $this->SetValue('nextBookingStatus', ($nextBooking['statusId'] == 2 ? $this->Translate('approved') : $this->Translate('requested')));
        } else {
            $this->SetValue('nextBookingStatus', '');
            $this->SetValue('nextBookingTitle', '');
            $this->SetValue('nextBookingStartDate', '');
            $this->SetValue('nextBookingEndDate', '');
        }
        $this->SetValue('roomInUse', $roomInUse);
        $this->SetValue('roomInUseWithPreheating', $roomInUseWithPreheating);
    }

    public function GetConfigurationForm()
    {
        $bookings = json_decode($this->ReadAttributeString('bookings'), true);
        $includingRequests = $this->ReadPropertyBoolean('treatRequestsAsBooked');
        $listValues = [];
        foreach ($bookings as $booking) {
            if (($booking['statusId'] != 0) && ($includingRequests || ($booking['statusId'] == 2))) {
                $startDate = new DateTime($booking['startDate']);
                $preheatDate = new DateTime($booking['startDate']);
                $preheatDate = $preheatDate->sub(new DateInterval('PT' . $this->ReadPropertyInteger('preheatingMinutes') . 'M'));
                $endDate = new DateTime($booking['endDate']);
                $stopHeatingDate = new DateTime($booking['endDate']);
                $stopHeatingDate = $stopHeatingDate->sub(new DateInterval('PT' . $this->ReadPropertyInteger('stopHeatingEarlyMinutes') . 'M'));
                $listValues[] = [
                    'startDate' => date($this->Translate('Y-m-d H:i:s'), $startDate->format('U')),
                    'endDate' => date($this->Translate('Y-m-d H:i:s'), $endDate->format('U')),
                    'name' => $booking['caption'],
                    'state' => ($booking['statusId'] == 2 ? $this->Translate('approved') : $this->Translate('requested')),
                    'heatingStart' => date($this->Translate('Y-m-d H:i:s'), $preheatDate->format('U')),
                    'heatingEnd' => date($this->Translate('Y-m-d H:i:s'), $stopHeatingDate->format('U'))
                ];
            }
        }

        $jsonForm = [
            'elements' => [
                [
                    'type' => 'NumberSpinner',
                    'name'=> 'roomID',
                    'caption' => 'roomID'
                ],
                [
                    'type' => 'CheckBox',
                    'name'=> 'treatRequestsAsBooked',
                    'caption' => 'Treat requests as booked'
                ],
                [
                    'type' => 'NumberSpinner',
                    'name'=> 'preheatingMinutes',
                    'caption' => 'Pre-heating',
                    'suffix' => 'minutes',
                    'minimum' => 0,
                    'maximum' => 1440
                ],
                [
                    'type' => 'NumberSpinner',
                    'name'=> 'stopHeatingEarlyMinutes',
                    'caption' => 'Stop heating early',
                    'suffix' => 'minutes',
                    'minimum' => 0,
                    'maximum' => 1440
                ]

            ],
            'actions' => [
                [
                    'type' => 'List',
                    'name' => 'bookings',
                    'caption' => 'Aktuelle Buchungen',
                    'rowCount' => 5,
                    'columns' => [
                        [
                            'caption' => 'Start',
                            'name' => 'startDate',
                            'width' => '150px'
                        ],
                        [
                            'caption' => 'End',
                            'name' => 'endDate',
                            'width' => '150px'
                        ],
                        [
                            'caption' => 'Name',
                            'name' => 'name',
                            'width' => 'auto'
                        ],
                        [
                            'caption' => 'State',
                            'name' => 'state',
                            'width' => '100px'
                        ],
                        [
                            'caption' => 'Heating start',
                            'name' => 'heatingStart',
                            'width' => '150px'
                        ],
                        [
                            'caption' => 'Heating end',
                            'name' => 'heatingEnd',
                            'width' => '150px'
                        ]
                    ],
                    'values' => $listValues
                ]
            ]
        ];
        return json_encode($jsonForm);
    }

}

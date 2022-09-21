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
        $this->RegisterVariableInteger('nextBookingStatusId', $this->Translate('Next booking status ID'));
        $this->SetValue('roomInUse', false);
        $this->SetValue('roomInUseWithPreheating', false);

        $this->RegisterTimer("Update", 10000, 'CTR_UpdateUsage('. $this->InstanceID . ');');
    }

    // Überschreibt die intere IPS_ApplyChanges($id) Funktion
    public function ApplyChanges()
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        $this->SetReceiveDataFilter('.*"resourceId":' . $this->ReadPropertyInteger('roomID') . ',.*');
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
    public function ReceiveData($JSONString) {
        $data = json_decode($JSONString, true);

        //Im Meldungsfenster zu Debug zwecken ausgeben
        IPS_LogMessage('CTRoomUsage #' . $this->ReadPropertyInteger('roomID'), print_r($data, true));

        $data = $data['Buffer'];

        if ($this->ReadPropertyBoolean('treatRequestsAsBooked') !== $data['includingRequests']) {
            return; // ignore
        }

        if ($data['statusId'] != 0) {
            $this->SetValue('nextBookingTitle', $data['caption']);
            $this->SetValue('nextBookingStartDate', $data['startDate']);
            $this->SetValue('nextBookingEndDate', $data['endDate']);
            $this->SetValue('nextBookingStatusId', $data['statusId']);
            $now = new DateTime();
            $startDate = new DateTime($data['startDate']);
            $preheatDate = new DateTime($data['startDate']);
            $preheatDate = $preheatDate->sub(new DateInterval('PT' . $this->ReadPropertyInteger('preheatingMinutes') . 'M'));
            $this->SetValue('roomInUse', ($now >= $startDate));
            $this->SetValue('roomInUseWithPreheating', ($now >= $preheatDate));
            $this->SetValue('nextBookingStatusId', $data['statusId']);
        } else {
            $this->SetValue('nextBookingStatusId', 0);
            $this->SetValue('nextBookingTitle', '');
            $this->SetValue('nextBookingStartDate', '');
            $this->SetValue('nextBookingEndDate', '');
            $this->SetValue('roomInUse', false);
            $this->SetValue('roomInUseWithPreheating', false);
        }
    }

    public function UpdateUsage() {
        if ($this->GetValue('nextBookingStatusId') === 0) {
            return;
        }
        $endDate = new DateTime($this->GetValue('nextBookingEndDate'));
        $endDate = $endDate->sub(new DateInterval('PT' . $this->ReadPropertyInteger('stopHeatingEarlyMinutes') . 'M'));
        $now = new DateTime();

        if ($now > $endDate) {
            $this->SetValue('nextBookingStatusId', 0);
            $this->SetValue('nextBookingTitle', '');
            $this->SetValue('nextBookingStartDate', '');
            $this->SetValue('nextBookingEndDate', '');
            $this->SetValue('roomInUse', false);
            $this->SetValue('roomInUseWithPreheating', false);
            return;
        }

        $startDate = new DateTime($this->GetValue('nextBookingStartDate'));
        $preheatDate = new DateTime($this->GetValue('nextBookingStartDate'));
        $preheatDate = $preheatDate->sub(new DateInterval('PT' . $this->ReadPropertyInteger('preheatingMinutes') . 'M'));
        $this->SetValue('roomInUse', ($now >= $startDate));
        $this->SetValue('roomInUseWithPreheating', ($now >= $preheatDate));
    }

    public function GetConfigurationForm()
    {
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
            ]
        ];
        return json_encode($jsonForm);
    }

}

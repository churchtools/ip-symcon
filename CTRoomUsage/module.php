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

        // Intelligente (adaptive) Heizrampe - alle Properties sind additiv und optional.
        // Ist 'adaptivePreheating' aus (Default), verhält sich das Modul exakt wie bisher.
        $this->RegisterPropertyBoolean('adaptivePreheating', false);
        $this->RegisterPropertyInteger('insideTempID', 0);
        $this->RegisterPropertyInteger('outsideTempID', 0);
        $this->RegisterPropertyFloat('targetTemperature', 21.0);
        $this->RegisterPropertyInteger('minPreheatingMinutes', 5);
        $this->RegisterPropertyInteger('maxPreheatingMinutes', 240);
        $this->RegisterPropertyBoolean('enableLearning', true);

        $this->RegisterVariableBoolean('roomInUse', $this->Translate('room in use')); // Variable: wird als Variable angezeigt und kann von außen abgerufen werden
        $this->RegisterVariableBoolean('roomInUseWithPreheating', $this->Translate('heating'));
        $this->RegisterVariableString('nextBookingTitle', $this->Translate('Next booking title'));
        $this->RegisterVariableString('nextBookingStartDate', $this->Translate('Next booking start date'));
        $this->RegisterVariableString('nextBookingEndDate', $this->Translate('Next booking end date'));
        $this->RegisterVariableString('nextBookingStatus', $this->Translate('Next booking status'));
        $this->RegisterVariableInteger('dynamicPreheatMinutes', $this->Translate('Dynamic pre-heating (minutes)'));
        $this->SetValue('roomInUse', false);
        $this->SetValue('roomInUseWithPreheating', false);

        $this->RegisterTimer("Update", 10000, 'CTR_UpdateUsage('. $this->InstanceID . ');');

        $this->RegisterAttributeString('bookings', '[]');

        // Persistenter Zustand der Lernlogik
        $this->RegisterAttributeString('heatingModel', json_encode($this->defaultHeatingModel()));
        $this->RegisterAttributeString('learningLog', '[]');
        $this->RegisterAttributeString('activeRamp', 'null');
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

        // Vorlauf einmalig bestimmen: adaptiv (falls aktiv und Sensoren gültig) oder fester Wert.
        $preheatMinutes = $this->ComputePreheatMinutes();
        $this->SetValue('dynamicPreheatMinutes', $preheatMinutes);

        $nextBooking = null;
        $roomInUse = false;
        $roomInUseWithPreheating = false;
        $now = new DateTime();
        $includingRequests = $this->ReadPropertyBoolean('treatRequestsAsBooked');

        foreach (array_reverse($bookings) as $booking) {
            if (($booking['statusId'] != 0) && ($includingRequests || ($booking['statusId'] == 2))) {
                $startDate = new DateTime($booking['startDate']);
                $preheatDate = new DateTime($booking['startDate']);
                $preheatDate = $preheatDate->sub(new DateInterval('PT' . $preheatMinutes . 'M'));
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

        // Selbstlernen: echte Aufheizzeit messen und Heizrate kalibrieren.
        $this->UpdateLearning($roomInUseWithPreheating);
    }

    /**
     * Standard-Parameter des lernenden Heizmodells.
     * ratePerMin  : Aufheizrate in °C/min bei Referenz-Außentemperatur (wird gelernt)
     * outsideCoeff: Empfindlichkeit der Rate gegenüber der Außentemperatur (°C/min pro °C)
     * refOutside  : Referenz-Außentemperatur, auf die ratePerMin bezogen ist
     * samples     : Anzahl der bereits eingeflossenen Messungen
     */
    private function defaultHeatingModel(): array
    {
        return [
            'ratePerMin'   => 0.15,
            'outsideCoeff' => 0.004,
            'refOutside'   => 20.0,
            'samples'      => 0,
        ];
    }

    private function readHeatingModel(): array
    {
        $model = json_decode($this->ReadAttributeString('heatingModel'), true);
        if (!is_array($model)) {
            return $this->defaultHeatingModel();
        }
        // Fehlende Schlüssel mit Defaults auffüllen (robust gegen alte Zustände).
        return array_merge($this->defaultHeatingModel(), $model);
    }

    /**
     * Liest eine Temperatur-Variable sicher aus. Gibt null zurück, wenn die
     * Variable fehlt oder keinen numerischen Wert liefert.
     */
    private function readTemperature(int $variableID)
    {
        if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
            return null;
        }
        $value = GetValue($variableID);
        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    /**
     * Berechnet den Heiz-Vorlauf in Minuten.
     *
     * Ist die intelligente Rampe deaktiviert oder sind die Temperatur-Variablen
     * ungültig, wird der klassische feste Wert 'preheatingMinutes' zurückgegeben
     * (voll abwärtskompatibel). Andernfalls:
     *
     *   Vorlauf = (T_ziel - T_innen) / Heizrate(T_außen)
     *   Heizrate(T_außen) = ratePerMin - outsideCoeff * (refOutside - T_außen)
     *
     * Ergebnis wird auf [minPreheatingMinutes, maxPreheatingMinutes] begrenzt.
     */
    public function ComputePreheatMinutes(): int
    {
        $fallback = $this->ReadPropertyInteger('preheatingMinutes');

        if (!$this->ReadPropertyBoolean('adaptivePreheating')) {
            return $fallback;
        }

        $tInside = $this->readTemperature($this->ReadPropertyInteger('insideTempID'));
        $tOutside = $this->readTemperature($this->ReadPropertyInteger('outsideTempID'));
        if ($tInside === null || $tOutside === null) {
            return $fallback;
        }

        $target = $this->ReadPropertyFloat('targetTemperature');
        $min = $this->ReadPropertyInteger('minPreheatingMinutes');
        $max = $this->ReadPropertyInteger('maxPreheatingMinutes');

        // Raum bereits warm genug -> minimaler Vorlauf.
        $delta = $target - $tInside;
        if ($delta <= 0) {
            return max(0, $min);
        }

        $model = $this->readHeatingModel();
        $rate = $model['ratePerMin'] - $model['outsideCoeff'] * ($model['refOutside'] - $tOutside);
        $rate = max(0.02, $rate); // niemals unter physikalisch sinnvolle Untergrenze

        $minutes = (int) ceil($delta / $rate);

        if ($minutes < $min) {
            $minutes = $min;
        }
        if ($minutes > $max) {
            $minutes = $max;
        }
        return $minutes;
    }

    /**
     * Zustandsmaschine für das Selbstlernen. Wird jeden Zyklus mit dem aktuellen
     * Heizstatus aufgerufen. Startet bei Rampenbeginn eine Messung, ermittelt beim
     * Erreichen der Zieltemperatur die echte Aufheizzeit und kalibriert das Modell.
     */
    private function UpdateLearning(bool $heatingActive): void
    {
        if (!$this->ReadPropertyBoolean('adaptivePreheating') || !$this->ReadPropertyBoolean('enableLearning')) {
            return;
        }

        $tInside = $this->readTemperature($this->ReadPropertyInteger('insideTempID'));
        $tOutside = $this->readTemperature($this->ReadPropertyInteger('outsideTempID'));
        if ($tInside === null || $tOutside === null) {
            return;
        }

        $target = $this->ReadPropertyFloat('targetTemperature');
        $active = json_decode($this->ReadAttributeString('activeRamp'), true);
        $now = time();

        // Keine Heizung aktiv: laufende (unvollständige) Messung verwerfen.
        if (!$heatingActive) {
            if (is_array($active)) {
                $this->WriteAttributeString('activeRamp', 'null');
            }
            return;
        }

        // Heizung aktiv, aber noch keine Messung: nur starten, wenn es etwas aufzuheizen gibt.
        if (!is_array($active)) {
            if (($target - $tInside) > 0.3) {
                $this->WriteAttributeString('activeRamp', json_encode([
                    'startedAt'    => $now,
                    'tInsideStart' => $tInside,
                    'tOutside'     => $tOutside,
                    'predictedMin' => $this->GetValue('dynamicPreheatMinutes'),
                ]));
            }
            return;
        }

        // Messung läuft: Zieltemperatur erreicht -> auswerten.
        if ($tInside >= $target) {
            $elapsedMin = max(1, (int) round(($now - (int) $active['startedAt']) / 60));
            $this->recordHeatup($active, $elapsedMin, $now);
            $this->WriteAttributeString('activeRamp', 'null');
        }
    }

    /**
     * Verarbeitet eine abgeschlossene Aufheizmessung: aktualisiert die gelernte
     * Heizrate per exponentiell gleitendem Mittel und pflegt den Lernverlauf
     * (Ringpuffer der letzten 20 Ereignisse).
     */
    private function recordHeatup(array $active, int $actualMin, int $finishedAt): void
    {
        $model = $this->readHeatingModel();
        $target = $this->ReadPropertyFloat('targetTemperature');

        $deltaStart = $target - (float) $active['tInsideStart'];
        if ($deltaStart > 0 && $actualMin > 0) {
            // Beobachtete Rate bei der gemessenen Außentemperatur auf die
            // Referenz-Außentemperatur zurückrechnen, dann per EMA glätten.
            $observedRate = $deltaStart / $actualMin;
            $observedRef = $observedRate + $model['outsideCoeff'] * ($model['refOutside'] - (float) $active['tOutside']);
            $observedRef = max(0.02, min(2.0, $observedRef));

            $alpha = 0.2;
            if (((int) ($model['samples'] ?? 0)) < 1) {
                $model['ratePerMin'] = $observedRef; // erster Messwert zählt voll
            } else {
                $model['ratePerMin'] = (1 - $alpha) * $model['ratePerMin'] + $alpha * $observedRef;
            }
            $model['ratePerMin'] = max(0.02, min(2.0, $model['ratePerMin']));
            $model['samples'] = ((int) ($model['samples'] ?? 0)) + 1;
            $this->WriteAttributeString('heatingModel', json_encode($model));
        }

        $log = json_decode($this->ReadAttributeString('learningLog'), true);
        if (!is_array($log)) {
            $log = [];
        }
        $log[] = [
            'finishedAt'   => $finishedAt,
            'tOutside'     => (float) $active['tOutside'],
            'tInsideStart' => (float) $active['tInsideStart'],
            'predictedMin' => (int) ($active['predictedMin'] ?? 0),
            'actualMin'    => $actualMin,
        ];
        if (count($log) > 20) {
            $log = array_slice($log, -20);
        }
        $this->WriteAttributeString('learningLog', json_encode($log));
    }

    public function GetConfigurationForm()
    {
        $bookings = json_decode($this->ReadAttributeString('bookings'), true);
        $includingRequests = $this->ReadPropertyBoolean('treatRequestsAsBooked');
        $preheatMinutes = $this->ComputePreheatMinutes();
        $listValues = [];
        foreach ($bookings as $booking) {
            if (($booking['statusId'] != 0) && ($includingRequests || ($booking['statusId'] == 2))) {
                $startDate = new DateTime($booking['startDate']);
                $preheatDate = new DateTime($booking['startDate']);
                $preheatDate = $preheatDate->sub(new DateInterval('PT' . $preheatMinutes . 'M'));
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

        // Lernverlauf für die Anzeige aufbereiten (neueste zuerst).
        $model = $this->readHeatingModel();
        $log = json_decode($this->ReadAttributeString('learningLog'), true);
        if (!is_array($log)) {
            $log = [];
        }
        $learnValues = [];
        foreach (array_reverse($log) as $entry) {
            $learnValues[] = [
                'finishedAt'   => date($this->Translate('Y-m-d H:i:s'), (int) $entry['finishedAt']),
                'tOutside'     => number_format((float) $entry['tOutside'], 1) . ' °C',
                'tInsideStart' => number_format((float) $entry['tInsideStart'], 1) . ' °C',
                'predictedMin' => ((int) $entry['predictedMin']) . ' min',
                'actualMin'    => ((int) $entry['actualMin']) . ' min',
            ];
        }

        $modelInfo = sprintf(
            $this->Translate('Learned heating rate: %s °C/min · sensitivity: %s · samples: %d'),
            number_format((float) $model['ratePerMin'], 3),
            number_format((float) $model['outsideCoeff'], 3),
            (int) $model['samples']
        );

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
                    'caption' => 'Pre-heating (fixed / fallback)',
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
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Intelligent pre-heating ramp',
                    'items' => [
                        [
                            'type' => 'Label',
                            'caption' => 'When enabled, the pre-heating lead time is calculated from inside/outside temperature so the room reaches the target temperature right at booking start. When disabled (or sensors invalid), the fixed value above is used.'
                        ],
                        [
                            'type' => 'CheckBox',
                            'name' => 'adaptivePreheating',
                            'caption' => 'Enable intelligent ramp'
                        ],
                        [
                            'type' => 'SelectVariable',
                            'name' => 'insideTempID',
                            'caption' => 'Inside temperature variable'
                        ],
                        [
                            'type' => 'SelectVariable',
                            'name' => 'outsideTempID',
                            'caption' => 'Outside temperature variable'
                        ],
                        [
                            'type' => 'NumberSpinner',
                            'name' => 'targetTemperature',
                            'caption' => 'Target temperature',
                            'suffix' => '°C',
                            'digits' => 1,
                            'minimum' => 5,
                            'maximum' => 40
                        ],
                        [
                            'type' => 'NumberSpinner',
                            'name' => 'minPreheatingMinutes',
                            'caption' => 'Minimum pre-heating',
                            'suffix' => 'minutes',
                            'minimum' => 0,
                            'maximum' => 1440
                        ],
                        [
                            'type' => 'NumberSpinner',
                            'name' => 'maxPreheatingMinutes',
                            'caption' => 'Maximum pre-heating',
                            'suffix' => 'minutes',
                            'minimum' => 0,
                            'maximum' => 1440
                        ],
                        [
                            'type' => 'CheckBox',
                            'name' => 'enableLearning',
                            'caption' => 'Self-learning (calibrate rate from measured heat-up times)'
                        ],
                        [
                            'type' => 'Label',
                            'name' => 'modelInfo',
                            'caption' => $modelInfo
                        ]
                    ]
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
                ],
                [
                    'type' => 'List',
                    'name' => 'learningLog',
                    'caption' => 'Learning history',
                    'rowCount' => 5,
                    'columns' => [
                        [
                            'caption' => 'Finished',
                            'name' => 'finishedAt',
                            'width' => '150px'
                        ],
                        [
                            'caption' => 'Outside',
                            'name' => 'tOutside',
                            'width' => '90px'
                        ],
                        [
                            'caption' => 'Inside at start',
                            'name' => 'tInsideStart',
                            'width' => '110px'
                        ],
                        [
                            'caption' => 'Predicted',
                            'name' => 'predictedMin',
                            'width' => '100px'
                        ],
                        [
                            'caption' => 'Measured',
                            'name' => 'actualMin',
                            'width' => '100px'
                        ]
                    ],
                    'values' => $learnValues
                ]
            ]
        ];
        return json_encode($jsonForm);
    }

}

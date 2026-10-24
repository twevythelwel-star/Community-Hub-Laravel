<?php

namespace App\Livewire\Realtime;

use App\Services\Realtime\RealtimeBroadcasterService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class RealtimeOperationsHub extends Component
{
    public string $activeTab = 'telemetry'; // telemetry, chat, tracking, operations

    // Chat room state
    public string $selectedRoom = 'general';

    public string $chatMessageInput = '';

    public array $chatMessages = [];

    // Live dashboard metrics
    public int $activeVisitorsCount = 24;

    public int $activePassesCount = 68;

    public int $recentScansCount = 142;

    public int $gateClearanceSeconds = 18;

    public string $systemStatus = 'optimal';

    // Gate pass tracking state
    public array $recentPassEvents = [];

    // Operations command center alerts
    public array $activeAlerts = [];

    public string $newAlertSeverity = 'warning';

    public string $newAlertHeadline = 'Perimeter optical tripwire triggered in Sector 4';

    public string $newAlertLocation = 'North Boundary Gate 2';

    // Feedback
    public ?string $feedbackMessage = null;

    /**
     * The security desk's command view: it pushes alerts, pass events and the
     * dashboard's figures to everyone watching, so it is `manageSecurity`
     * only. Each broadcasting action re-checks, since mount() runs once.
     */
    public function mount(): void
    {
        $this->authorize('manageSecurity');

        // Seed default chat messages
        $this->chatMessages = [
            [
                'id' => 1,
                'user_name' => 'Alexander Wright',
                'user_role' => 'System Admin',
                'message' => 'Real-time WebSocket server (Laravel Reverb) is operational on port 8080.',
                'sent_at' => now()->subMinutes(12)->format('H:i'),
            ],
            [
                'id' => 2,
                'user_name' => 'Elena Rostova',
                'user_role' => 'Operations Director',
                'message' => 'Gatehouse 1 scanner firmware synced with Echo listeners.',
                'sent_at' => now()->subMinutes(5)->format('H:i'),
            ],
        ];

        // Seed recent pass events
        $this->recentPassEvents = [
            [
                'pass_code' => 'GP-8492',
                'visitor' => 'Marcus Vance Jr.',
                'gate' => 'Main Gate 1',
                'status' => 'cleared',
                'time' => now()->subMinutes(3)->format('H:i:s'),
            ],
            [
                'pass_code' => 'GP-3108',
                'visitor' => 'Courier Delivery (DHL)',
                'gate' => 'Service Gate',
                'status' => 'scanned',
                'time' => now()->subMinutes(1)->format('H:i:s'),
            ],
        ];

        // Seed active operations alert
        $this->activeAlerts = [
            [
                'id' => 'ALT-SEC-101',
                'type' => 'gate_traffic',
                'severity' => 'info',
                'headline' => 'Peak visitor check-in window initiated',
                'location' => 'Main Gate 1',
                'time' => now()->subMinutes(8)->format('H:i'),
            ],
        ];
    }

    public function selectTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function sendChatMessage(RealtimeBroadcasterService $broadcaster): void
    {
        $this->authorize('manageSecurity');

        if (trim($this->chatMessageInput) === '') {
            return;
        }

        $user = Auth::user();
        $senderName = $user->name;
        $senderRole = $user->role->value;

        $broadcaster->broadcastChatMessage(
            roomId: $this->selectedRoom,
            user: $user,
            message: Str::limit($this->chatMessageInput, 2000, '')
        );

        $this->chatMessages[] = [
            'id' => count($this->chatMessages) + 1,
            'user_name' => $senderName,
            'user_role' => $senderRole,
            'message' => $this->chatMessageInput,
            'sent_at' => now()->format('H:i'),
        ];

        $this->chatMessageInput = '';
        $this->feedbackMessage = 'Message broadcasted in real time via Reverb!';
    }

    public function dispatchOperationsAlert(RealtimeBroadcasterService $broadcaster): void
    {
        $this->authorize('manageSecurity');

        // Public properties are browser-settable; keep them to the form's values.
        $this->validate([
            'newAlertSeverity' => ['required', 'in:info,warning,critical'],
            'newAlertHeadline' => ['required', 'string', 'max:160'],
            'newAlertLocation' => ['required', 'string', 'max:120'],
        ]);

        $operator = Auth::user()->name;

        $event = $broadcaster->broadcastOperationsAlert(
            type: 'security_monitor',
            severity: $this->newAlertSeverity,
            headline: $this->newAlertHeadline,
            location: $this->newAlertLocation,
            operatorName: $operator
        );

        array_unshift($this->activeAlerts, [
            'id' => $event->alertId,
            'type' => $event->type,
            'severity' => $event->severity,
            'headline' => $event->headline,
            'location' => $event->location,
            'time' => now()->format('H:i'),
        ]);

        $this->feedbackMessage = "Operations Alert [{$event->alertId}] broadcasted on [presence-operations-center]!";
    }

    public function render(RealtimeBroadcasterService $broadcaster): View
    {
        $echoConfig = $broadcaster->getEchoConfig();

        return view('livewire.realtime.realtime-operations-hub', [
            'echoConfig' => $echoConfig,
        ])->layout('layouts.app');
    }
}

<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ConsultationRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public $consultationData;

    public function __construct($consultationData)
    {
        $this->consultationData = $consultationData;
    }

    public function build()
    {
        return $this->subject('New Consultation Request - ' . $this->consultationData['eventType'])
                    ->view('emails.consultation-request')
                    ->with('data', $this->consultationData);
    }
}
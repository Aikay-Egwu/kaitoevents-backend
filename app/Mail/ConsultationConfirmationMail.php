<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ConsultationConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $consultationData;

    public function __construct($consultationData)
    {
        $this->consultationData = $consultationData;
    }

    public function build()
    {
        return $this->subject('Consultation Request Received - Kaito Events')
                    ->view('emails.consultation-confirmation')
                    ->with('data', $this->consultationData);
    }
}
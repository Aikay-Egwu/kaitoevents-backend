<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Consultation Request Received</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
        .section { margin-bottom: 20px; }
        .label { font-weight: bold; color: #555; }
        .value { margin-bottom: 10px; }
        .footer { background: #f8f9fa; padding: 15px; border-radius: 5px; text-align: center; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Thank You for Your Consultation Request!</h2>
            <p>We've received your request and will get back to you within 24 hours.</p>
        </div>

        <div class="section">
            <p>Dear {{ $data['firstName'] }},</p>
            <p>Thank you for choosing Kaito Events for your upcoming {{ $data['eventType'] }}. We're excited to help make your event memorable!</p>
        </div>

        <div class="section">
            <h3>Your Request Summary</h3>
            <div class="value"><span class="label">Event Type:</span> {{ $data['eventType'] }}</div>
            <div class="value"><span class="label">Event Date:</span> {{ $data['eventDate'] ? date('F j, Y', strtotime($data['eventDate'])) : 'To be determined' }}</div>
            <div class="value"><span class="label">Guest Count:</span> {{ $data['guestCount'] }}</div>
            @if($data['budget'])
                <div class="value"><span class="label">Budget Range:</span> {{ $data['budget'] }}</div>
            @endif
        </div>

        <div class="section">
            <h3>What's Next?</h3>
            <ul>
                <li>Our team will review your request within 24 hours</li>
                <li>We'll contact you to schedule your consultation</li>
                <li>During the consultation, we'll discuss your vision and requirements in detail</li>
                <li>We'll provide you with a customized proposal for your event</li>
            </ul>
        </div>

        <div class="section">
            <p>If you have any immediate questions, please don't hesitate to contact us:</p>
            <p><strong>Email:</strong> info@kaitoevents.co.uk<br>
            <strong>Phone:</strong>01915659575</p>
        </div>

        <div class="footer">
            <p><strong>Kaito Events</strong><br>
            Creating Unforgettable Moments</p>
        </div>
    </div>
</body>
</html>
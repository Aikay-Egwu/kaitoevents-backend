<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New Consultation Request</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .section { margin-bottom: 20px; }
        .label { font-weight: bold; color: #555; }
        .value { margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>New Consultation Request</h2>
            <p>A new consultation request has been submitted through your website.</p>
        </div>

        <div class="section">
            <h3>Personal Information</h3>
            <div class="value"><span class="label">Name:</span> {{ $data['firstName'] }} {{ $data['lastName'] }}</div>
            <div class="value"><span class="label">Email:</span> {{ $data['email'] }}</div>
            <div class="value"><span class="label">Phone:</span> {{ $data['phone'] }}</div>
        </div>

        <div class="section">
            <h3>Event Details</h3>
            <div class="value"><span class="label">Event Type:</span> {{ $data['eventType'] }}</div>
            <div class="value"><span class="label">Event Date:</span> {{ $data['eventDate'] ? date('F j, Y', strtotime($data['eventDate'])) : 'Not specified' }}</div>
            <div class="value"><span class="label">Guest Count:</span> {{ $data['guestCount'] }}</div>
            <div class="value"><span class="label">Has Venue:</span> {{ ucfirst($data['hasVenue']) }}</div>
            
            @if($data['hasVenue'] === 'yes')
                <div class="value"><span class="label">Venue Name:</span> {{ $data['venueName'] }}</div>
                <div class="value"><span class="label">Venue Address:</span> {{ $data['venueAddress'] }}</div>
            @endif
        </div>

        <div class="section">
            <h3>Optional Details</h3>
            @if($data['budget'])
                <div class="value"><span class="label">Budget:</span> {{ $data['budget'] }}</div>
            @endif
            @if($data['colorScheme'])
                <div class="value"><span class="label">Color Scheme:</span> {{ $data['colorScheme'] }}</div>
            @endif
            @if($data['startTime'])
                <div class="value"><span class="label">Start Time:</span> {{ $data['startTime'] }}</div>
            @endif
            @if($data['specialInstructions'])
                <div class="value"><span class="label">Special Instructions:</span> {{ $data['specialInstructions'] }}</div>
            @endif
        </div>

        <div class="section">
            <p><strong>Please respond to this consultation request within 24 hours.</strong></p>
        </div>
    </div>
</body>
</html>
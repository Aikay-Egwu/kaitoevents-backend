<!DOCTYPE html>
<html>

<head>
  <meta charset="utf-8">
  <title>Admin Message</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      line-height: 1.6;
      color: #333;
    }

    .container {
      max-width: 600px;
      margin: 0 auto;
      padding: 20px;
    }

    .header {
      background: #f8f9fa;
      padding: 20px;
      border-radius: 5px;
      margin-bottom: 20px;
      text-align: center;
    }

    .section {
      margin-bottom: 20px;
    }

    .label {
      font-weight: bold;
      color: #555;
    }

    .value {
      margin-bottom: 10px;
    }

    .footer {
      background: #f8f9fa;
      padding: 15px;
      border-radius: 5px;
      text-align: center;
      margin-top: 20px;
    }
  </style>
</head>

<body>
  <div class="container">


    <div class="section">
      <p>Hi {{ $name }},</p>
      <p>{{ $message }}</p>
    </div>

    <div class="footer">
      <p><strong>Kaito Events</strong><br>
        Creating Unforgettable Moments</p>
    </div>
  </div>
</body>

</html>
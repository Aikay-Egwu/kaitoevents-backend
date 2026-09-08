<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Kaito Events</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #2c3e50; padding: 30px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px;">Welcome to Kaito Events</h1>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding: 30px;">
                            <p style="font-size: 16px; margin-bottom: 16px;">Dear {{ $data['name'] }},</p>

                            <p style="font-size: 16px; margin-bottom: 16px;">
                                An admin account has been created for you on the <strong>Kaito Events</strong> management platform.
                                You can now log in and manage events, clients, inventory, and more.
                            </p>

                            <!-- Account Details -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f8f9fa; border-radius: 6px; margin: 20px 0;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <h3 style="margin: 0 0 12px 0; color: #2c3e50; font-size: 18px;">Your Account Details</h3>
                                        <table cellpadding="8" cellspacing="0">
                                            <tr>
                                                <td style="font-weight: bold; color: #555; white-space: nowrap;">Email:</td>
                                                <td style="color: #333;">{{ $data['email'] }}</td>
                                            </tr>
                                            <tr>
                                                <td style="font-weight: bold; color: #555; white-space: nowrap;">Temporary Password:</td>
                                                <td style="color: #333; font-family: monospace; font-size: 15px;">{{ $data['password'] }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- CTA -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #e8f4fd; border-radius: 6px; margin: 20px 0;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <h3 style="margin: 0 0 8px 0; color: #2c3e50; font-size: 16px;">⚠️ Important — Change Your Password</h3>
                                        <p style="margin: 0; font-size: 14px; color: #555;">
                                            For security reasons, please log in and change your password to something you'll remember
                                            as soon as possible.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 14px; color: #777; margin-top: 24px;">
                                If you have any questions or need assistance, please contact the Kaito Events team.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #2c3e50; padding: 20px; text-align: center;">
                            <p style="color: #ffffff; margin: 0 0 4px 0; font-size: 16px; font-weight: bold;">Kaito Events</p>
                            <p style="color: #bdc3c7; margin: 0 0 4px 0; font-size: 13px;">Creating Unforgettable Moments</p>
                            <p style="color: #95a5a6; margin: 0; font-size: 12px;">
                                Email: hello@kaitoevents.co.uk &nbsp;|&nbsp; www.kaitoevents.co.uk
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

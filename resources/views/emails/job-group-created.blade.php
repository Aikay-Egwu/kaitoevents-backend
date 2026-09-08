<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Group Assignment - {{ $data['group_name'] }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <table width="640" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #2c3e50; padding: 30px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 22px;">
                                Team Assignment: {{ $data['group_name'] }}
                            </h1>
                            <p style="color: #bdc3c7; margin: 8px 0 0 0; font-size: 14px;">
                                Kaito Events — Event Job Management
                            </p>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding: 30px;">
                            <p style="font-size: 16px; margin-bottom: 16px;">
                                Dear <strong>{{ $data['recipient_name'] }}</strong>,
                            </p>

                            <p style="font-size: 15px; margin-bottom: 20px;">
                                You've been added to the <strong>{{ $data['group_name'] }}</strong> team
                                for an upcoming event. Below are all the details for the event and tasks
                                assigned to your team.
                            </p>

                            <!-- Group & Event Details -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f8f9fa; border-radius: 6px; margin: 20px 0;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <h3 style="margin: 0 0 14px 0; color: #2c3e50; font-size: 17px;">Group &amp; Event Information</h3>
                                        <table cellpadding="6" cellspacing="0">
                                            <tr>
                                                <td style="font-weight: bold; color: #555; white-space: nowrap;">Group Name:</td>
                                                <td style="color: #333;">{{ $data['group_name'] }}</td>
                                            </tr>
                                            @if(!empty($data['group_description']))
                                            <tr>
                                                <td style="font-weight: bold; color: #555; white-space: nowrap;">Group Scope:</td>
                                                <td style="color: #333;">{{ $data['group_description'] }}</td>
                                            </tr>
                                            @endif
                                            <tr>
                                                <td style="font-weight: bold; color: #555; white-space: nowrap;">Team Lead:</td>
                                                <td style="color: #333;">
                                                    {{ $data['team_lead']['name'] }}
                                                    <span style="color: #888; font-size: 13px;">({{ $data['team_lead']['email'] }})</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="font-weight: bold; color: #555; white-space: nowrap;">Team Size:</td>
                                                <td style="color: #333;">{{ $data['member_count'] }} member(s)</td>
                                            </tr>
                                            <tr><td colspan="2" style="height: 8px;"></td></tr>
                                            <tr>
                                                <td style="font-weight: bold; color: #555; white-space: nowrap;">Event Title:</td>
                                                <td style="color: #333;">{{ $data['event']['title'] }}</td>
                                            </tr>
                                            <tr>
                                                <td style="font-weight: bold; color: #555; white-space: nowrap;">Event Date:</td>
                                                <td style="color: #333;">{{ $data['event']['date'] }}</td>
                                            </tr>
                                            <tr>
                                                <td style="font-weight: bold; color: #555; white-space: nowrap;">Venue:</td>
                                                <td style="color: #333;">{{ $data['event']['venue'] }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Assigned Tasks -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #eef7ff; border-radius: 6px; margin: 20px 0;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <h3 style="margin: 0 0 12px 0; color: #2c3e50; font-size: 17px;">
                                            Tasks for this Team ({{ count($data['tasks']) }})
                                        </h3>

                                        @if(count($data['tasks']) === 0)
                                            <p style="color: #666; font-size: 14px; margin: 0;">
                                                No tasks have been assigned yet. Check back soon, or speak with
                                                the event coordinator.
                                            </p>
                                        @else
                                            @foreach($data['tasks'] as $idx => $task)
                                                <table width="100%" cellpadding="10" cellspacing="0"
                                                       style="background-color: #fff; border-radius: 6px; margin-bottom: 10px; border-left: 4px solid #3498db;">
                                                    <tr>
                                                        <td style="width: 30px; color: #3498db; font-weight: bold; vertical-align: top;">
                                                            {{ $idx + 1 }}.
                                                        </td>
                                                        <td style="color: #333;">
                                                            <div style="font-weight: 600; font-size: 15px; margin-bottom: 4px;">
                                                                {{ $task['title'] }}
                                                            </div>
                                                            @if(!empty($task['instructions']))
                                                                <div style="font-size: 13px; color: #555; margin-bottom: 6px;">
                                                                    {!! nl2br(e($task['instructions'])) !!}
                                                                </div>
                                                            @endif
                                                            <div style="font-size: 12px; color: #777;">
                                                                <span style="display: inline-block; margin-right: 12px;">
                                                                    <strong>Location:</strong>
                                                                    {{ $task['location'] === 'on_site' ? 'On-site' : 'In-house' }}
                                                                </span>
                                                                <span style="display: inline-block; margin-right: 12px;">
                                                                    <strong>Status:</strong>
                                                                    {{ ucfirst(str_replace('_', ' ', $task['status'])) }}
                                                                </span>
                                                                @if(!empty($task['duration_minutes']))
                                                                    <span style="display: inline-block;">
                                                                        <strong>Estimated:</strong>
                                                                        {{ $task['duration_minutes'] }} min
                                                                    </span>
                                                                @endif
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            @endforeach
                                        @endif
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 14px; color: #777; margin-top: 24px;">
                                If you have any questions, contact the team lead or the Kaito Events coordinator
                                for further information.
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

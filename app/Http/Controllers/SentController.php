<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SentDm\Client;

class SentController extends Controller
{
    protected $client;

    public function __construct(Client $client)
    {
        $this->client = new Client(env('SENT_KEY'));
    }

    public function index()
    {
        return view('sent.index');
    }

    //
    public function sent(Request $request)
    {
        $result = $this->client->messages->send(
            to: ['+447442025899'],
            template: [
                'id' => 'db1ebb06-f4c7-478f-882f-9d536446a484',
                'name' => 'Party Eden',
                'parameters' => [
                    'name' => 'John Doe',
                    'order_id' => '12345'
                ]
            ],
            channel: ['sms', 'whatsapp', 'rcs'] // Optional
        );
        return $request->all();
        
    }
}

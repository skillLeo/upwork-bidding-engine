<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\MessageDirection;
use App\Http\Requests\Clients\DraftReplyRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\MessageResource;
use App\Jobs\DraftReplyJob;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Message;
use Illuminate\Http\JsonResponse;

class ClientController extends Controller
{
    public function show(Client $client): JsonResponse
    {
        return response()->json(['data' => new ClientResource($client->load(['lead', 'messages']))]);
    }

    public function draftReply(DraftReplyRequest $request, Client $client): JsonResponse
    {
        $message = Message::create([
            'client_id' => $client->id,
            'direction' => MessageDirection::In,
            'text' => $request->validated('message'),
            'sent_at' => now(),
        ]);

        ActivityLog::record(ActivityType::ReplyReceived, subject: $client, meta: [
            'message_id' => $message->id,
        ], userId: $request->user()?->id);

        DraftReplyJob::dispatchSync($message->id);

        return response()->json(['data' => new MessageResource($message->fresh())]);
    }
}

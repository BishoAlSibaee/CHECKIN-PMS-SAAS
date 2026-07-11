<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientNote\DeleteClientNoteRequest;
use App\Http\Requests\ClientNote\GetNotesByClientIdRequest;
use App\Http\Requests\ClientNote\StoreClientNoteRequest;
use App\Http\Requests\ClientNote\UpdateClientNoteRequest;
use App\Models\ClientNote;

class ClientNoteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GetNotesByClientIdRequest $request)
    {
        try {
            $clientId = $request->client_id;
            $notes = ClientNote::where('client_id', $clientId)
                ->orderBy('created_at', 'desc')
                ->get();

            return \SuccessData('Notes fetched successfully', $notes);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreClientNoteRequest $request)
    {
        try {
            $note = ClientNote::create($request->all());

            return \SuccessData('Note added successfully', $note);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateClientNoteRequest $request)
    {
        try {
            $note = ClientNote::findOrFail($request->id);
            $note->update($request->all());

            return \SuccessData('Note updated successfully', $note);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DeleteClientNoteRequest $request)
    {
        try {
            $note = ClientNote::findOrFail($request->id);
            $note->delete();

            return \Success('Note deleted successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}

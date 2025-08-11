<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\ContactStoreRequest;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ContactController extends Controller
{
    /**
     * List authenticated user's contacts with optional search and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = (int) $request->integer('per_page', 15);
        $search = trim((string) $request->get('search', ''));

        $query = Contact::query()
            ->with(['contact' => function ($q) {
                $q->select('id', 'name', 'email', 'avatar');
            }])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at');

        if ($search !== '') {
            $query->whereHas('contact', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        $data = $paginator->getCollection()->map(function (Contact $contact) {
            $u = $contact->contact;
            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar_url' => $u->avatar_url ?? null,
                'added_at' => $contact->created_at,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Add a contact for the authenticated user.
     */
    public function store(ContactStoreRequest $request): JsonResponse
    {
        $user = $request->user();
        $contactUserId = (int) $request->validated()['contact_user_id'];

        if ($contactUserId === (int) $user->id) {
            return response()->json(['message' => __('You cannot add yourself as a contact.')], 422);
        }

        // Ensure the user exists (redundant with validation's exists rule, but keeps intent clear)
        $contactUser = User::query()->select('id')->findOrFail($contactUserId);

        $contact = Contact::firstOrCreate([
            'user_id' => $user->id,
            'contact_user_id' => $contactUser->id,
        ]);

        return response()->json([
            'message' => __('Contact added.'),
            'id' => $contact->id,
        ], 201);
    }

    /**
     * Remove a contact by contact user id.
     */
    public function destroy(Request $request, User $contactUser): JsonResponse
    {
        $user = $request->user();

        Contact::query()
            ->where('user_id', $user->id)
            ->where('contact_user_id', $contactUser->id)
            ->delete();

        return response()->json(['message' => __('Contact removed.')]);
    }
}

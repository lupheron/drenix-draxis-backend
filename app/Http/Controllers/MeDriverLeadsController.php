<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Integrations\Monday\DriverLeadWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeDriverLeadsController extends Controller
{
    public function __construct(
        private readonly DriverLeadWriteService $writes,
    ) {}

    public function move(Request $request): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        $allowedBoards = $this->writes->allowedBoardNames($employee);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'target_board' => ['required', 'string', 'max:255'],
        ]);

        if ($allowedBoards !== [] && ! in_array($validated['target_board'], $allowedBoards, true)) {
            return response()->json([
                'message' => 'Target board is not mapped to this employee.',
            ], 403);
        }

        $result = $this->writes->move(
            $employee,
            $validated['ids'],
            $validated['target_board'],
        );

        return response()->json(['data' => $result]);
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $result = $this->writes->delete($employee, $validated['ids']);

        return response()->json(['data' => $result]);
    }
}

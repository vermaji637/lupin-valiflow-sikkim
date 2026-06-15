<?php 
namespace App\Http\Controllers;

use App\Models\Protocol;
use App\Models\ProtocolWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProtocolController extends Controller
{
    public function create() {
        $users = \App\Models\User::orderBy('first_name')->get();
        
        return view('protocols.create', compact('users'));
    }

    public function store(Request $request) {
        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string',
            'reviewer_id'   => 'required|exists:users,id|different:checker_id|different:validator_id',
            'checker_id'    => 'required|exists:users,id|different:validator_id',
            'validator_id'  => 'required|exists:users,id',
        ]);

        DB::transaction(function () use ($data) {
            $protocol = Protocol::create([
                'owner_id'    => auth()->id(),
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'status'      => 'in_progress',
            ]);

            // Assign 3 steps
            ProtocolWorkflow::create([
                'protocol_id' => $protocol->id,
                'user_id'     => $data['reviewer_id'],
                'step'        => 'review',
                'sequence'    => 1,
            ]);
            ProtocolWorkflow::create([
                'protocol_id' => $protocol->id,
                'user_id'     => $data['checker_id'],
                'step'        => 'check',
                'sequence'    => 2,
            ]);
            ProtocolWorkflow::create([
                'protocol_id' => $protocol->id,
                'user_id'     => $data['validator_id'],
                'step'        => 'validation',
                'sequence'    => 3,
            ]);
        });

        return redirect()->route('dashboard.tasks')->with('success','Protocol created.');
    }

    public function completeStep(ProtocolWorkflow $workflow) {
        abort_if($workflow->user_id !== auth()->id(), 403, 'Not authorized');

        DB::transaction(function () use ($workflow) {
            // prevent skipping steps
            $previousPending = ProtocolWorkflow::where('protocol_id', $workflow->protocol_id)
                ->where('sequence', '<', $workflow->sequence)
                ->where('status', 'pending')
                ->exists();

            abort_if($previousPending, 422, 'Previous step not completed yet.');

            $workflow->update(['status' => 'completed']);

            // check if all done
            $stillPending = ProtocolWorkflow::where('protocol_id', $workflow->protocol_id)
                ->where('status','pending')->exists();

            if (! $stillPending) {
                $workflow->protocol->update(['status' => 'completed']);
            }
        });

        return back()->with('success','Step completed.');
    }
}

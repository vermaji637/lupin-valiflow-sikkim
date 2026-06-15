<?php 
namespace App\Http\Controllers;

use App\Models\ProtocolWorkflow;

class DashboardController extends Controller
{
    public function myTasks() {
        
        $tasks = ProtocolWorkflow::with(['protocol','protocol.workflows'])
            ->actionableFor(auth()->id())
            ->orderBy('sequence')
            ->latest('id')
            ->get();

        return view('dashboard.my_tasks', compact('tasks'));
    }
}

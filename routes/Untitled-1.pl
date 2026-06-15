use App\Models\User;
use Illuminate\Support\Facades\Hash;

$user = User::create([
    'first_name' => 'admin',
    'last_name'  => 'admin',
    'email'      => 'admin@admin.com',
    'password'   => Hash::make('Password.1'),
    'status'     => 1,
]);

<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $dumpPath = database_path('bhms_dump.sql');
        $hasUsers = false;
        try {
            $hasUsers = \Illuminate\Support\Facades\DB::table('users')->where('username', 'larp')->exists();
        } catch (\Throwable $e) {}

        if (! $hasUsers && file_exists($dumpPath)) {
            try {
                \Illuminate\Support\Facades\Artisan::call('db:import-dump');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('DB import dump failed: ' . $e->getMessage());
            }
        }

        $this->call(RoleSeeder::class);

        $sqlUsers = [
            [
                'id' => 1,
                'name' => 'Edcil Jhon B. Faciol',
                'username' => 'eji',
                'email' => 'edciljhonfaciol@gmail.com',
                'phone' => '09915633814',
                'role_id' => 4,
                'is_active' => 1,
                'password' => '$2y$12$nelUK8wKlDjsY8codBNDz.Aiaxis8yx9q2fDWkr2kN5efHaagsJMa',
            ],
            [
                'id' => 2,
                'name' => 'Kurt Laurence Arbues',
                'username' => 'kurtarbues',
                'email' => 'kurtlaurencearbues@gmail.com',
                'phone' => '09935215868',
                'role_id' => 1,
                'is_active' => 1,
                'password' => '$2y$12$mkoLKEbAyEJmFTFbnGl1Q.bAE2.nwjUNitPgMxtHcKkFkszG8UdV6',
            ],
            [
                'id' => 3,
                'name' => 'Giovanni P. Cantos',
                'username' => 'gio',
                'email' => 'giovannicantos18@gmail.com',
                'phone' => '09935478932',
                'role_id' => 2,
                'is_active' => 1,
                'password' => '$2y$12$GI5WB3itk4genbVnDkOiWuOTl.s6OACOgOuZ8YTwrXc/cr76Aepzm',
            ],
            [
                'id' => 4,
                'name' => 'Glendale Montehermoso',
                'username' => 'glendale',
                'email' => 'youknowglendale@gmail.com',
                'phone' => '09935215868',
                'role_id' => 4,
                'is_active' => 1,
                'password' => '$2y$12$UC4Y9pSTsrp7uIH0ljdJuuSCy2kIfjmPe0BexQw52mFXR.x6h7wn6',
            ],
            [
                'id' => 10,
                'name' => 'Ralph Christian Sabandal',
                'username' => 'larp',
                'email' => 'ralphchristian@gmail.com',
                'phone' => '09924917058',
                'role_id' => 4,
                'is_active' => 1,
                'password' => '$2y$12$zz.Px3quiPW49HOQAi2jmO/1CpJWDFnMe93ki0v7RSZ1tsJTXBNkm',
            ],
            [
                'id' => 11,
                'name' => 'Angel Stacee Tabligan',
                'username' => 'stayc',
                'email' => 'angelstacee@gmail.com',
                'phone' => '09450676188',
                'role_id' => 2,
                'is_active' => 1,
                'password' => '$2y$12$bNxYNjryK8cAQoHqenR5kOHpJNFl6pQZmIgZRtR76Duyc4lgYmBNW',
            ],
            [
                'id' => 12,
                'name' => 'Romy Tadlas',
                'username' => 'romy',
                'email' => 'romytadlas@gmail.com',
                'phone' => '09677676673',
                'role_id' => 4,
                'is_active' => 1,
                'password' => '$2y$12$a/Dk1K8yErSCnJ3NoDI.EOSWFy67/J5XMHRuKphVu/ZTct8K9grU2',
            ],
        ];

        foreach ($sqlUsers as $userData) {
            \Illuminate\Support\Facades\DB::table('users')->updateOrInsert(
                ['email' => $userData['email']],
                $userData
            );
        }

        $adminRole = Role::where('name', Role::ADMIN)->first();
        $landlordRole = Role::where('name', Role::LANDLORD)->first();
        $tenantRole = Role::where('name', Role::TENANT)->first();

        // Default admin account for Kurt
        User::firstOrCreate(
            ['email' => 'kurtlaurencearbues@gmail.com'],
            [
                'name' => 'Kurt Laurence Arbues',
                'username' => 'kurtarbues',
                'phone' => '09935215868',
                'password' => Hash::make('admin123'),
                'role_id' => $adminRole->id,
                'is_active' => true,
            ]
        );

        $adminEmail = env('SEED_ADMIN_EMAIL');
        $adminPassword = env('SEED_ADMIN_PASSWORD');
        if ($adminEmail && $adminPassword && $adminEmail !== 'kurtlaurencearbues@gmail.com') {
            User::updateOrCreate(
                ['email' => $adminEmail],
                [
                    'name' => env('SEED_ADMIN_NAME', 'System Admin'),
                    'username' => env('SEED_ADMIN_USERNAME', 'admin'),
                    'phone' => env('SEED_ADMIN_PHONE'),
                    'password' => Hash::make($adminPassword),
                    'role_id' => $adminRole->id,
                    'is_active' => true,
                ]
            );
        }

        $landlord = User::firstOrCreate(
            ['email' => 'landlord@bhms.test'],
            [
                'name' => 'Maria Santos',
                'username' => 'landlord',
                'phone' => '09171234567',
                'password' => Hash::make('password'),
                'role_id' => $landlordRole->id,
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'tenant@bhms.test'],
            [
                'name' => 'Juan Dela Cruz',
                'username' => 'tenant',
                'phone' => '09179876543',
                'password' => Hash::make('password'),
                'role_id' => $tenantRole->id,
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['username' => 'stayc'],
            [
                'email' => 'stayc@bhms.test',
                'name' => 'Angel Stacee Tabligan',
                'phone' => '09171239876',
                'password' => Hash::make('angelako'),
                'role_id' => $tenantRole->id,
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'tenant2@bhms.test'],
            [
                'name' => 'Ana Reyes',
                'username' => 'tenant2',
                'phone' => '09175551234',
                'password' => Hash::make('password'),
                'role_id' => $tenantRole->id,
                'is_active' => true,
            ]
        );

        if (Room::where('landlord_id', $landlord->id)->count() === 0) {
            $rooms = [
                ['room_number' => '101', 'title' => 'Sunny Bedspace near Campus', 'type' => 'Bedspace', 'location' => 'Sampaloc, Manila', 'price' => 3500, 'amenities' => ['WiFi', 'Electric fan', 'Shared bath']],
                ['room_number' => '102', 'title' => 'Single Room with Window', 'type' => 'Single', 'location' => 'Sampaloc, Manila', 'price' => 6500, 'amenities' => ['WiFi', 'Own bath', 'Study desk']],
                ['room_number' => '201', 'title' => 'Shared Twin for Students', 'type' => 'Shared Twin', 'location' => 'Quezon City', 'price' => 4500, 'amenities' => ['WiFi', 'Two beds', 'Kitchen access']],
                ['room_number' => '202', 'title' => 'Studio with Kitchenette', 'type' => 'Studio', 'location' => 'Makati', 'price' => 12000, 'amenities' => ['WiFi', 'Kitchenette', 'Aircon', 'Own bath']],
                ['room_number' => '301', 'title' => 'Family Room Ground Floor', 'type' => 'Family', 'location' => 'Pasig', 'price' => 15000, 'amenities' => ['WiFi', 'Aircon', 'Two beds', 'Own bath']],
            ];

            foreach ($rooms as $room) {
                Room::create([
                    ...$room,
                    'landlord_id' => $landlord->id,
                    'description' => $room['title'].' listed from the boarding house inventory.',
                    'status' => 'available',
                ]);
            }
        }
    }
}

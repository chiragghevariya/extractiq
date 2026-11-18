<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/** One demo tenant with fictional users and vendors; documents come from /samples via extractiq:demo. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::updateOrCreate(['slug' => 'demo'], [
            'name' => 'Demo Manufacturing Co.',
            'base_currency' => 'USD',
            'settings' => ['timezone' => 'UTC'],
        ]);

        foreach ([
            ['Avery Admin', 'admin@extractiq.test', 'admin'],
            ['Riley Reviewer', 'reviewer@extractiq.test', 'reviewer'],
            ['Val Viewer', 'viewer@extractiq.test', 'viewer'],
        ] as [$name, $email, $role]) {
            // On a public demo the named accounts get unguessable passwords: visitors use the read-only login.
            $password = config('extractiq.demo.enabled') ? Str::random(40) : 'password';
            User::updateOrCreate(['email' => $email], ['company_id' => $company->id, 'name' => $name, 'password' => $password, 'role' => $role]);
        }
        // The public demo visitor (POST /auth/demo, only with DEMO_MODE=true): sees what an admin sees,
        // changes nothing (DemoReadOnly). No usable password.
        User::updateOrCreate(['email' => config('extractiq.demo.email')], [
            'company_id' => $company->id, 'name' => 'Demo visitor', 'password' => Str::random(48), 'role' => 'admin', 'is_demo' => true,
        ]);

        foreach ([
            ['Kestrel Industrial Parts LLC', '88-1234567', 'billing@kestrel-parts.example', 'USD'],
            ['Brightline Office Supply Co.', '77-7654321', 'ar@brightline-office.example', 'USD'],
            ['Harbor & Pine Packaging Ltd', 'DE123456789', 'accounts@harbor-pine.example', 'EUR'],
            ['Nimbus Cloud Services Inc', '66-1112223', 'invoices@nimbus-cloud.example', 'USD'],
        ] as [$name, $tax, $email, $cur]) {
            Vendor::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'normalized_name' => Vendor::normalize($name)],
                ['name' => $name, 'tax_id' => $tax, 'email' => $email, 'default_currency' => $cur],
            );
        }

        if (! app()->runningUnitTests() && is_file(base_path('../samples/manifest.json'))) {
            Artisan::call('extractiq:demo', [], $this->command->getOutput());
        }
    }
}

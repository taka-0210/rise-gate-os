<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Improvement;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RiseGateOsOperationSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'takami@rise-gate.local'],
            ['name' => 'Takami Masaya', 'password' => Hash::make('password')]
        );

        $organization = Organization::firstOrCreate(['slug' => 'rise-gate'], ['name' => 'Rise Gate']);
        $workspace = Workspace::firstOrCreate(
            ['organization_id' => $organization->id, 'slug' => 'rise-gate'],
            ['name' => 'Rise Gate']
        );

        $organization->users()->syncWithoutDetaching([$user->id => ['role' => 'owner', 'joined_at' => now()]]);
        $workspace->users()->syncWithoutDetaching([$user->id => ['role' => 'owner', 'joined_at' => now()]]);

        $riseGateClient = Client::firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Rise Gate internal'],
            ['organization_id' => $organization->id, 'website' => 'https://rise-gate.com']
        );

        $hitOkinawaClient = Client::firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Pro Chubo Hit Okinawa Co., Ltd.'],
            ['organization_id' => $organization->id]
        );

        $riseGateOsProject = $this->project($organization, $workspace, $riseGateClient, $user, 'Rise Gate OS', 'RGOS', Project::PRIORITY_URGENT);
        $riseGateHomeProject = $this->project($organization, $workspace, $riseGateClient, $user, 'Rise Gate Website', 'RG-WEB', Project::PRIORITY_HIGH);
        $hitOkinawaProject = $this->project($organization, $workspace, $hitOkinawaClient, $user, 'Pro Chubo Hit Okinawa Website', 'HIT-WEB', Project::PRIORITY_NORMAL);

        foreach ([$riseGateOsProject, $riseGateHomeProject, $hitOkinawaProject] as $project) {
            $this->ensureOwnerMember($project, $workspace, $user);
        }

        $this->improvement($organization, $workspace, $riseGateOsProject, $user, 'Project Members implementation', Improvement::STATUS_IMPLEMENTED, 'Projects can manage internal members, partners, and clients.');
        $this->improvement($organization, $workspace, $riseGateOsProject, $user, 'Improvement UI refinement', Improvement::STATUS_PROPOSED, 'Improve editing, status updates, and daily operation UI.');
        $this->improvement($organization, $workspace, $riseGateOsProject, $user, 'Dashboard improvement', Improvement::STATUS_PLANNED, 'Show active projects, open improvements, and next actions.');
        $this->improvement($organization, $workspace, $riseGateOsProject, $user, 'Unregistered user invitation', Improvement::STATUS_PROPOSED, 'Invite users who do not have accounts yet.');
        $this->improvement($organization, $workspace, $riseGateOsProject, $user, 'Client contact management', Improvement::STATUS_PROPOSED, 'Manage client contacts and connect them to users and project members.');
        $this->improvement($organization, $workspace, $riseGateOsProject, $user, 'Improvement editing', Improvement::STATUS_IMPLEMENTED, 'Improvements must be editable so status, assignee, result, impact, and next action can grow through operation.');
        $this->improvement($organization, $workspace, $riseGateOsProject, $user, 'Project editing', Improvement::STATUS_IMPLEMENTED, 'Projects must be editable because client, summary, due date, and priority can change during operation.');        $this->improvement($organization, $workspace, $riseGateHomeProject, $user, 'Make website improvements project based', Improvement::STATUS_PROPOSED, 'Gather website improvements in the project.');
        $this->improvement($organization, $workspace, $hitOkinawaProject, $user, 'Visualize pre-release website checks', Improvement::STATUS_PROPOSED, 'Share pre-release checks with client-visible improvements.', Improvement::VISIBILITY_CLIENT);
    }

    private function project(Organization $organization, Workspace $workspace, Client $client, User $owner, string $name, string $code, string $priority): Project
    {
        return Project::updateOrCreate(
            ['owning_workspace_id' => $workspace->id, 'code' => $code],
            [
                'organization_id' => $organization->id,
                'billing_workspace_id' => $workspace->id,
                'client_id' => $client->id,
                'owner_user_id' => $owner->id,
                'name' => $name,
                'summary' => $name.' operation project.',
                'status' => Project::STATUS_ACTIVE,
                'priority' => $priority,
                'start_date' => now()->toDateString(),
            ]
        );
    }

    private function ensureOwnerMember(Project $project, Workspace $workspace, User $user): void
    {
        ProjectMember::updateOrCreate(
            ['project_id' => $project->id, 'user_id' => $user->id],
            [
                'workspace_id' => $workspace->id,
                'project_role' => ProjectMember::ROLE_OWNER,
                'permission_level' => ProjectMember::PERMISSION_ADMIN,
                'invited_by' => $user->id,
                'invited_at' => now(),
                'accepted_at' => now(),
                'status' => ProjectMember::STATUS_ACTIVE,
            ]
        );
    }

    private function improvement(Organization $organization, Workspace $workspace, Project $project, User $user, string $title, string $status, string $problem, string $visibility = Improvement::VISIBILITY_INTERNAL): void
    {
        Improvement::updateOrCreate(
            ['project_id' => $project->id, 'title' => $title],
            [
                'organization_id' => $organization->id,
                'workspace_id' => $workspace->id,
                'current_state' => $problem,
                'desired_state' => 'Record, operate, and improve this item through Rise Gate OS.',
                'problem' => $problem,
                'hypothesis' => 'Managing this as an Improvement will keep the decision and next action visible.',
                'next_action' => 'Review scope and decide implementation timing.',
                'status' => $status,
                'visibility' => $visibility,
                'proposed_by' => $user->id,
                'assigned_to' => $user->id,
                'implemented_by' => $status === Improvement::STATUS_IMPLEMENTED ? $user->id : null,
                'implemented_at' => $status === Improvement::STATUS_IMPLEMENTED ? now()->subDays(2) : null,
            ]
        );
    }
}


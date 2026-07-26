<script setup>
import { onMounted, reactive, ref, watch } from "vue";
import { toast } from "vue-sonner";
import { UserPlus, Trash2, RefreshCw, SlidersHorizontal, Building2 } from "@lucide/vue";
import MemberOverridesPanel from "@/components/settings/MemberOverridesPanel.vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldHint from "@/components/ui/FieldHint.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";
import { relativeTime } from "@/lib/utils";

const auth = useAuthStore();

// EXACTLY TWO INVITABLE ROLES (P8). A workspace has one owner — you — and
// everyone you bring in is a bidder or a viewer. Owner is reached by
// transferring the workspace, never by invitation, so it is not an option
// here and the server refuses it regardless of what is posted.
const ROLE_OPTIONS = [
  { value: "bidder", label: "Bidder", hint: "Works the pipeline: leads, proposals, clients. No secret keys, no members." },
  { value: "viewer", label: "Viewer", hint: "Read-only. For someone watching output without touching it." },
];

// Shown on the members list, where an existing owner still has to render.
const ROLE_LABELS = { owner: "Owner", bidder: "Bidder", viewer: "Viewer" };

const members = ref([]);
const invitations = ref([]);
const loading = ref(true);
const inviteEmail = ref("");
const inviteRole = ref("bidder");
const inviting = ref(false);
const busyId = ref(null);
const overridesFor = ref(null); // member whose personal-permissions panel is open

// ------------------------------------------- give someone their own workspace
// Same endpoint the platform console uses. Surfaced here because this is the
// screen people open when they want to add a person, and the two paths need
// to be visible side by side or the wrong one wins by default.
const creatingWorkspace = ref(false);
const wsSlugTouched = ref(false);
const wsForm = reactive({ name: "", slug: "", specialization: "", owner_email: "", owner_name: "", owner_password: "" });

watch(
  () => wsForm.name,
  (name) => {
    if (!wsSlugTouched.value) {
      wsForm.slug = name.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
    }
  },
);

async function createWorkspace() {
  creatingWorkspace.value = true;
  try {
    const res = await apiClient.post("/platform/tenants", {
      name: wsForm.name.trim(),
      slug: wsForm.slug.trim(),
      specialization: wsForm.specialization.trim() || null,
      owner_email: wsForm.owner_email.trim(),
      owner_name: wsForm.owner_name.trim() || null,
      // Blank means "email them an invitation instead" — the server picks
      // the door based on whether a password came through.
      owner_password: wsForm.owner_password.trim() || null,
    });
    toast.success(res.data.data.message, { duration: 10000 });
    Object.assign(wsForm, { name: "", slug: "", specialization: "", owner_email: "", owner_name: "", owner_password: "" });
    wsSlugTouched.value = false;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not create the workspace."));
  } finally {
    creatingWorkspace.value = false;
  }
}

// The same two, always. Kept as a function so the template reads the same as
// before; there is no longer any branch on who is asking, because only the
// owner can reach this endpoint at all.
const roleChoices = () => ROLE_OPTIONS;

async function load() {
  loading.value = true;
  try {
    const res = await apiClient.get("/members");
    members.value = res.data.data.members;
    invitations.value = res.data.data.invitations;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not load members."));
  } finally {
    loading.value = false;
  }
}

async function sendInvite() {
  if (!inviteEmail.value.trim()) return;
  inviting.value = true;
  try {
    const res = await apiClient.post("/members/invite", { email: inviteEmail.value.trim(), role: inviteRole.value });
    toast.success(res.data.data.message);
    inviteEmail.value = "";
    await load();
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not send the invitation."));
  } finally {
    inviting.value = false;
  }
}

async function changeRole(member, role) {
  busyId.value = member.id;
  try {
    const res = await apiClient.put(`/members/${member.id}/role`, { role });
    toast.success(res.data.data.message);
    await load();
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not change the role."));
    await load();
  } finally {
    busyId.value = null;
  }
}

async function removeMember(member) {
  if (!confirm(`Remove ${member.name} from the workspace? Their sessions end immediately.`)) return;
  busyId.value = member.id;
  try {
    const res = await apiClient.delete(`/members/${member.id}`);
    toast.success(res.data.data.message);
    await load();
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not remove the member."));
  } finally {
    busyId.value = null;
  }
}

async function resend(invitation) {
  busyId.value = `inv-${invitation.id}`;
  try {
    const res = await apiClient.post(`/members/${invitation.id}/resend`);
    toast.success(res.data.data.message);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not resend the invitation."));
  } finally {
    busyId.value = null;
  }
}

onMounted(load);
</script>

<template>
  <div class="space-y-4">
    <!-- THE CHOICE, STATED ONCE, BEFORE EITHER FORM.
         Adding someone to your workspace and giving someone a workspace of
         their own are completely different acts with the same everyday word
         ("add a person") attached to both. Only one of them used to be on
         this screen, so the wrong one was the only one anybody could find. -->
    <Card>
      <CardContent class="space-y-3 pt-5">
        <p class="text-sm font-semibold text-text-primary">Two different things live here</p>
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="rounded-md border border-border bg-neutral-bg/50 p-3">
            <p class="text-sm font-medium text-text-primary">Someone who works on YOUR leads</p>
            <p class="mt-1 text-xs text-text-secondary">
              A bidder or viewer. They see this workspace's leads, stacks and rules, because it
              is the workspace they are working in. Use the invite form below.
            </p>
          </div>
          <div
            class="rounded-md border p-3"
            :class="auth.isPlatformOwner ? 'border-primary/30 bg-primary-tint/40' : 'border-border bg-neutral-bg/50'"
          >
            <p class="text-sm font-medium text-text-primary">Someone who runs their OWN workspace</p>
            <p class="mt-1 text-xs text-text-secondary">
              Their own stacks, their own leads, their own filters — invisible to you, and yours
              invisible to them. That is a separate workspace, not an invitation.
              <template v-if="!auth.isPlatformOwner">Only the platform owner can create one.</template>
            </p>
          </div>
        </div>
      </CardContent>
    </Card>

    <!-- Platform owner only: the workspace-creation path, put HERE rather
         than left buried in the platform console, because this is the screen
         people actually open when they want to add somebody. -->
    <Card v-if="auth.isPlatformOwner">
      <CardHeader>
        <CardTitle class="flex items-center gap-2">
          <Building2 class="h-4 w-4 text-primary" /> Give someone their own workspace
        </CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <CardDescription>
          Creates a separate workspace on a trial and emails that person an owner invitation.
          They fill in their own stacks and project facts and start from nothing — they will
          never see this workspace's leads, and you will never see theirs.
        </CardDescription>

        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Workspace name</Label>
            <Input v-model="wsForm.name" placeholder="Northwind Design" />
          </div>
          <div>
            <Label>Slug</Label>
            <Input v-model="wsForm.slug" @input="wsSlugTouched = true" placeholder="northwind-design" class="font-mono" />
          </div>
          <div>
            <Label>Specialization <span class="text-text-tertiary">(optional)</span></Label>
            <Input v-model="wsForm.specialization" placeholder="Graphic design" />
          </div>
          <div>
            <Label>Their email</Label>
            <Input v-model="wsForm.owner_email" type="email" placeholder="owner@northwind.com" />
          </div>
          <div>
            <Label>Their name <span class="text-text-tertiary">(optional)</span></Label>
            <Input v-model="wsForm.owner_name" placeholder="Nora Owner" />
          </div>
          <div>
            <Label>Set their password <span class="text-text-tertiary">(optional)</span></Label>
            <Input v-model="wsForm.owner_password" type="password" autocomplete="new-password" placeholder="Leave blank to email an invitation" />
            <FieldHint>
              Type a password and their account is created and usable immediately — no email
              involved. Leave it blank and they get an invitation link instead. Use the password
              route if email delivery is unreliable; hand them the password yourself and let them
              change it in their profile.
            </FieldHint>
          </div>
        </div>

        <div class="flex justify-end">
          <Button
            :loading="creatingWorkspace"
            :disabled="!wsForm.name || !wsForm.slug || !wsForm.owner_email"
            @click="createWorkspace"
          >
            Create workspace &amp; invite owner
          </Button>
        </div>
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle class="flex items-center gap-2">
          <UserPlus class="h-4 w-4 text-primary" /> Invite someone into THIS workspace
        </CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <CardDescription>
          They'll get an email with a single-use link that expires in 72 hours, and they join
          <strong class="font-semibold text-text-primary">this</strong> workspace — sharing its
          leads, stacks and bidding rules with you.
        </CardDescription>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
          <div class="flex-1">
            <Label>Email</Label>
            <Input v-model="inviteEmail" type="email" placeholder="teammate@company.com" @keyup.enter="sendInvite" />
          </div>
          <div class="sm:w-48">
            <Label>Role</Label>
            <select
              v-model="inviteRole"
              class="h-9 w-full rounded-md border border-border-strong bg-white px-2.5 text-sm text-text-primary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
            >
              <option v-for="r in roleChoices()" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
          </div>
          <Button :loading="inviting" @click="sendInvite">Send invite</Button>
        </div>
        <p class="text-xs text-text-tertiary">
          {{ roleChoices().find((r) => r.value === inviteRole)?.hint }}
        </p>
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle>Members</CardTitle>
      </CardHeader>
      <CardContent class="p-0">
        <div v-if="loading" class="space-y-2 p-5">
          <Skeleton v-for="i in 3" :key="i" class="h-12 w-full" />
        </div>
        <template v-else>
          <ul class="divide-y divide-border">
            <template v-for="m in members" :key="m.id">
            <li class="flex items-center justify-between gap-3 px-5 py-3">
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-text-primary">
                  {{ m.name }}
                  <span v-if="m.is_self" class="text-xs text-text-tertiary">(you)</span>
                </p>
                <p class="truncate text-xs text-text-tertiary">
                  {{ m.email }}
                  <span v-if="m.last_active_at"> · active {{ relativeTime(m.last_active_at) }}</span>
                </p>
              </div>
              <div class="flex items-center gap-2">
                <Button
                  v-if="!m.is_owner && auth.can('permissions.edit')"
                  variant="ghost"
                  size="sm"
                  :title="`Personal permission exceptions for ${m.name}`"
                  @click="overridesFor = overridesFor?.id === m.id ? null : m"
                >
                  <SlidersHorizontal class="h-3.5 w-3.5" /> Permissions
                </Button>
                <!-- The owner is a label, not a dropdown: 'owner' is not one
                     of the two assignable roles, so a select would render
                     blank against it. Ownership moves through Transfer
                     ownership on the Workspace tab. -->
                <span
                  v-if="m.is_owner"
                  class="rounded-md border border-border bg-neutral-bg px-2 py-1 text-xs font-medium text-text-secondary"
                >
                  Owner
                </span>
                <select
                  v-else
                  :value="m.role"
                  :disabled="busyId === m.id || !auth.can('members.assign_role')"
                  @change="changeRole(m, $event.target.value)"
                  class="h-8 rounded-md border border-border-strong bg-white px-2 text-xs font-medium text-text-secondary focus:border-primary focus:outline-none disabled:opacity-60"
                >
                  <option v-for="r in ROLE_OPTIONS" :key="r.value" :value="r.value">{{ r.label }}</option>
                </select>
                <Button
                  v-if="!m.is_owner && !m.is_self && auth.can('members.remove')"
                  variant="ghost"
                  size="icon"
                  :loading="busyId === m.id"
                  @click="removeMember(m)"
                >
                  <Trash2 class="h-4 w-4 text-danger" />
                </Button>
              </div>
            </li>
            <li v-if="overridesFor?.id === m.id" class="px-5 py-3">
              <MemberOverridesPanel :member="overridesFor" @close="overridesFor = null" />
            </li>
            </template>
          </ul>

          <div v-if="invitations.length" class="border-t border-border">
            <p class="px-5 pt-4 text-xs font-semibold uppercase tracking-wide text-text-tertiary">Pending invitations</p>
            <ul class="divide-y divide-border">
              <li v-for="inv in invitations" :key="inv.id" class="flex items-center justify-between gap-3 px-5 py-3">
                <div class="min-w-0">
                  <p class="truncate text-sm text-text-primary">{{ inv.email }}</p>
                  <p class="text-xs text-text-tertiary">
                    {{ ROLE_LABELS[inv.role] ?? inv.role }} ·
                    <span :class="inv.status === 'expired' ? 'text-danger' : 'text-warning'">{{ inv.status }}</span>
                  </p>
                </div>
                <Button variant="secondary" size="sm" :loading="busyId === `inv-${inv.id}`" @click="resend(inv)">
                  <RefreshCw class="h-3.5 w-3.5" /> Resend
                </Button>
              </li>
            </ul>
          </div>
        </template>
      </CardContent>
    </Card>
  </div>
</template>

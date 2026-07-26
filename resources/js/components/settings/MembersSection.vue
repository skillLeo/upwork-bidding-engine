<script setup>
import { onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import { UserPlus, Trash2, RefreshCw, SlidersHorizontal } from "@lucide/vue";
import MemberOverridesPanel from "@/components/settings/MemberOverridesPanel.vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
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
    <Card>
      <CardHeader>
        <CardTitle class="flex items-center gap-2">
          <UserPlus class="h-4 w-4 text-primary" /> Invite a teammate
        </CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <CardDescription>
          They'll get an email with a single-use link that expires in 72 hours.
          <strong class="font-semibold text-text-primary">
            Anyone you invite here joins THIS workspace
          </strong>
          — they share your leads, your stacks, and your bidding rules. That is what a bidder is
          for. If you want someone who runs their own separate workspace, with their own stacks
          and their own leads that you cannot see and they cannot see yours, that is a new
          workspace, not an invitation.
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

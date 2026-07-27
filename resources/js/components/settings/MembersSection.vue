<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue";
import { toast } from "vue-sonner";
import { UserPlus, Trash2, RefreshCw, SlidersHorizontal } from "@lucide/vue";
import MemberOverridesPanel from "@/components/settings/MemberOverridesPanel.vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldHint from "@/components/ui/FieldHint.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";
import { relativeTime } from "@/lib/utils";

const auth = useAuthStore();

// "Admin" is NOT a role inside this workspace — there is no such thing, and
// bringing one back would immediately raise "can an admin invite an admin?",
// the ambiguity the three-role hierarchy exists to remove. It is offered here
// because it is the word people actually use for "someone who runs their own
// shop", and picking it creates a SEPARATE WORKSPACE with that person as its
// owner. The label says so; the fields that appear say so; the server enforces
// it either way (an admin invitation into this workspace is a 403).
const ADMIN_OPTION = {
  value: "admin",
  label: "Admin — their own workspace",
  hint: "Creates a separate workspace they own. Their own stacks, leads, filters and bidding rules — you will not see theirs and they will not see yours.",
};

const ROLE_OPTIONS = [
  { value: "bidder", label: "Bidder", hint: "Joins THIS workspace. Works the pipeline: leads, proposals, clients. Shares your leads, stacks and rules." },
  { value: "viewer", label: "Viewer", hint: "Joins THIS workspace, read-only. For someone watching output without touching it." },
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
const wsForm = reactive({ name: "", slug: "", specialization_preset: "", owner_email: "", owner_name: "", owner_password: "" });

// Fetched rather than hardcoded so the dropdown can never offer a trade the
// server would reject.
const presets = ref([]);

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
      // The preset carries its own label, so the free-text field is only
      // needed when nobody picked one.
      specialization_preset: wsForm.specialization_preset || null,
      // The one email field at the top of the form is the person either way.
      owner_email: inviteEmail.value.trim(),
      owner_name: wsForm.owner_name.trim() || null,
      // Blank means "email them an invitation instead" — the server picks
      // the door based on whether a password came through.
      owner_password: wsForm.owner_password.trim() || null,
    });
    toast.success(res.data.data.message, { duration: 10000 });
    Object.assign(wsForm, { name: "", slug: "", specialization_preset: "", owner_email: "", owner_name: "", owner_password: "" });
    inviteEmail.value = "";
    wsSlugTouched.value = false;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not create the workspace."));
  } finally {
    creatingWorkspace.value = false;
  }
}

// Only the platform owner can create a workspace, so only they are offered
// Admin. Everyone else sees the two roles that join THIS workspace.
const roleChoices = () => (auth.isPlatformOwner ? [ADMIN_OPTION, ...ROLE_OPTIONS] : ROLE_OPTIONS);

const canSubmitAdd = computed(() => {
  if (!inviteEmail.value.trim()) return false;

  return inviteRole.value === "admin" ? Boolean(wsForm.name.trim() && wsForm.slug.trim()) : true;
});

/**
 * One button, two outcomes. Admin creates a workspace; anything else invites
 * into this one. The branch lives here rather than in two separate forms
 * because "add a person" is one action in the operator's head.
 */
function submitAdd() {
  return inviteRole.value === "admin" ? createWorkspace() : sendInvite();
}

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

  // Platform-owner only, and quietly optional: a workspace owner opening
  // this screen to invite a bidder has no business seeing this endpoint, and
  // a 403 here must not read as "members failed to load".
  try {
    const res = await apiClient.get("/platform/specializations");
    presets.value = res.data.data.presets;
  } catch {
    presets.value = [];
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
    <!-- ONE FORM, ONE ROLE DROPDOWN.
         This screen used to carry two cards — "invite a teammate" and
         "give someone their own workspace" — and the operator reached for
         the invite form five separate times looking for an Admin option
         that lived in the other card. Splitting something people think of
         as a single act ("add a person, pick what they are") across two
         cards means the wrong one gets used every time. So: one form, and
         the ROLE decides what actually happens. -->
    <Card>
      <CardHeader>
        <CardTitle class="flex items-center gap-2">
          <UserPlus class="h-4 w-4 text-primary" /> Add someone
        </CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
          <div class="flex-1">
            <Label>Email</Label>
            <Input v-model="inviteEmail" type="email" placeholder="person@company.com" @keyup.enter="submitAdd" />
          </div>
          <div class="sm:w-60">
            <Label>Role</Label>
            <select
              v-model="inviteRole"
              class="h-9 w-full rounded-md border border-border-strong bg-white px-2.5 text-sm text-text-primary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
            >
              <option v-for="r in roleChoices()" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
          </div>
        </div>

        <p class="text-xs text-text-secondary">{{ roleChoices().find((r) => r.value === inviteRole)?.hint }}</p>

        <!-- Admin only: an admin does NOT join this workspace, they get one
             of their own, so that workspace has to be named right here. -->
        <div v-if="inviteRole === 'admin'" class="space-y-3 rounded-md border border-primary/30 bg-primary-tint/30 p-3">
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <Label>Their workspace name</Label>
              <Input v-model="wsForm.name" placeholder="Northwind Design" />
            </div>
            <div>
              <Label>Slug</Label>
              <Input v-model="wsForm.slug" @input="wsSlugTouched = true" placeholder="northwind-design" class="font-mono" />
            </div>
            <div>
              <Label>What they do</Label>
              <select
                v-model="wsForm.specialization_preset"
                class="h-9 w-full rounded-md border border-border-strong bg-white px-3 text-sm text-text-primary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
              >
                <option value="">Let them set it up themselves</option>
                <option v-for="p in presets" :key="p.key" :value="p.key">{{ p.label }}</option>
              </select>
              <FieldHint>
                Fills in their starting stacks so their leads score from day one. They can change
                all of it later in their own Bidding rules.
              </FieldHint>
            </div>
            <div>
              <Label>Their name <span class="text-text-tertiary">(optional)</span></Label>
              <Input v-model="wsForm.owner_name" placeholder="Nora Owner" />
            </div>
          </div>
          <div>
            <Label>Set their password <span class="text-text-tertiary">(optional)</span></Label>
            <Input v-model="wsForm.owner_password" type="password" autocomplete="new-password" placeholder="Leave blank to email them an invitation" />
            <FieldHint>
              Type a password and their account works immediately, with no email involved —
              the safer route while mail delivery is unreliable. Leave it blank and they get
              an invitation link instead.
            </FieldHint>
          </div>
        </div>

        <div class="flex justify-end">
          <Button :loading="inviting || creatingWorkspace" :disabled="!canSubmitAdd" @click="submitAdd">
            {{ inviteRole === "admin" ? "Create workspace & add admin" : "Send invite" }}
          </Button>
        </div>
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

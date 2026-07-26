<script setup>
import { onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import { Building2, Download, ShieldAlert, Users } from "@lucide/vue";
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

const auth = useAuthStore();

const loading = ref(true);
const saving = ref(false);
const workspace = ref(null);
const form = ref({ name: "", slug: "", specialization: "" });

const members = ref([]);
const membersLoading = ref(false);
const transferTargetId = ref("");
const transferring = ref(false);

const exporting = ref(false);

const confirmSlug = ref("");
const deleting = ref(false);

async function load() {
  loading.value = true;
  try {
    const res = await apiClient.get("/workspace");
    workspace.value = res.data.data;
    form.value = {
      name: workspace.value.name,
      slug: workspace.value.slug,
      specialization: workspace.value.specialization ?? "",
    };
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not load workspace details."));
  } finally {
    loading.value = false;
  }
}

async function loadMembersForTransfer() {
  if (auth.tenantRole !== "owner" || members.value.length) return;
  membersLoading.value = true;
  try {
    const res = await apiClient.get("/members");
    members.value = res.data.data.members.filter((m) => !m.is_self);
  } catch {
    // Transfer panel just stays empty; not critical to the rest of the tab.
  } finally {
    membersLoading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    const res = await apiClient.put("/workspace", form.value);
    workspace.value = { ...workspace.value, ...res.data.data };
    toast.success("Workspace saved.");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save workspace."));
  } finally {
    saving.value = false;
  }
}

async function transferOwnership() {
  if (!transferTargetId.value) return;
  const target = members.value.find((m) => m.id === Number(transferTargetId.value));
  if (!confirm(`Transfer ownership to ${target?.name}? You will become a bidder in this workspace.`)) return;

  transferring.value = true;
  try {
    const res = await apiClient.post("/workspace/transfer-ownership", {
      new_owner_user_id: transferTargetId.value,
    });
    toast.success(res.data.data.message);
    await load();
    window.location.reload(); // role/permissions changed for the current user
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not transfer ownership."));
  } finally {
    transferring.value = false;
  }
}

async function exportData() {
  exporting.value = true;
  try {
    const res = await apiClient.get("/workspace/export", { responseType: "blob" });
    const url = window.URL.createObjectURL(new Blob([res.data]));
    const a = document.createElement("a");
    a.href = url;
    a.download = `${workspace.value?.slug ?? "workspace"}-export.json`;
    a.click();
    window.URL.revokeObjectURL(url);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not export workspace data."));
  } finally {
    exporting.value = false;
  }
}

async function destroyWorkspace() {
  if (confirmSlug.value !== workspace.value?.slug) return;
  if (!confirm("This deletes the workspace and signs everyone out. This cannot be undone from here. Continue?")) return;

  deleting.value = true;
  try {
    await apiClient.delete("/workspace", { data: { confirm_slug: confirmSlug.value } });
    toast.success("Workspace deleted.");
    auth.logout();
    window.location.href = "/login";
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not delete workspace."));
  } finally {
    deleting.value = false;
  }
}

onMounted(() => {
  load();
  loadMembersForTransfer();
});
</script>

<template>
  <div class="space-y-4">
    <Card>
      <CardHeader>
        <CardTitle class="flex items-center gap-2">
          <Building2 class="h-4 w-4 text-primary" /> Workspace
        </CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <div v-if="loading" class="space-y-2">
          <Skeleton v-for="i in 4" :key="i" class="h-9 w-full" />
        </div>
        <template v-else>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <Label>Name</Label>
              <Input v-model="form.name" />
            </div>
            <div>
              <Label>Slug</Label>
              <Input v-model="form.slug" />
            </div>
          </div>
          <div>
            <Label>Specialization</Label>
            <Input v-model="form.specialization" placeholder="Laravel + Vue, Graphic design, DevOps…" />
            <FieldHint>
              A label for how this workspace describes itself. It is display only — what your
              proposals claim and how leads are scored comes from your stack lists under
              Bidding Rules, never from this field.
            </FieldHint>
          </div>
          <div class="grid gap-4 sm:grid-cols-3 text-sm">
            <div>
              <p class="text-xs font-medium text-text-tertiary uppercase tracking-wide">Plan</p>
              <p class="mt-1 text-text-primary">{{ workspace.plan }}</p>
            </div>
            <div>
              <p class="text-xs font-medium text-text-tertiary uppercase tracking-wide">Status</p>
              <p class="mt-1 text-text-primary capitalize">{{ workspace.status }}</p>
            </div>
            <div>
              <p class="text-xs font-medium text-text-tertiary uppercase tracking-wide">Members</p>
              <p class="mt-1 flex items-center gap-1 text-text-primary">
                <Users class="h-3.5 w-3.5 text-text-tertiary" /> {{ workspace.member_count }}
              </p>
            </div>
          </div>
          <div class="flex justify-end">
            <Button @click="save" :loading="saving">Save workspace</Button>
          </div>
        </template>
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle>Export all data</CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <CardDescription>
          Downloads a JSON file with this workspace's leads, clients, and members. Secret settings
          (API keys, tokens) are never included.
        </CardDescription>
        <Button variant="secondary" :loading="exporting" @click="exportData">
          <Download class="h-3.5 w-3.5" /> Export workspace data
        </Button>
      </CardContent>
    </Card>

    <Card v-if="auth.tenantRole === 'owner'">
      <CardHeader>
        <CardTitle>Transfer ownership</CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <CardDescription>
          Makes someone else the owner. You become a bidder. Only the owner can transfer ownership,
          and only to an existing member.
        </CardDescription>
        <div class="flex flex-col gap-3 sm:flex-row">
          <select
            v-model="transferTargetId"
            :disabled="membersLoading"
            class="h-9 flex-1 rounded-md border border-border-strong bg-white px-2.5 text-sm text-text-primary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
          >
            <option value="">Choose a member…</option>
            <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }} ({{ m.email }})</option>
          </select>
          <Button variant="secondary" :disabled="!transferTargetId" :loading="transferring" @click="transferOwnership">
            Transfer
          </Button>
        </div>
      </CardContent>
    </Card>

    <Card v-if="auth.tenantRole === 'owner'" class="border-danger/30">
      <CardHeader>
        <CardTitle class="flex items-center gap-2 text-danger">
          <ShieldAlert class="h-4 w-4" /> Delete workspace
        </CardTitle>
      </CardHeader>
      <CardContent class="space-y-3">
        <CardDescription>
          Every member is signed out immediately. There is no self-service undo — contact support to
          restore a deleted workspace.
        </CardDescription>
        <Label>Type <span class="font-mono font-semibold">{{ workspace?.slug }}</span> to confirm</Label>
        <Input v-model="confirmSlug" :placeholder="workspace?.slug" />
        <div class="flex justify-end">
          <Button
            variant="danger"
            :disabled="confirmSlug !== workspace?.slug"
            :loading="deleting"
            @click="destroyWorkspace"
          >
            Delete workspace
          </Button>
        </div>
      </CardContent>
    </Card>
  </div>
</template>

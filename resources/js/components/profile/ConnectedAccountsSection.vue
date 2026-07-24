<script setup>
import { onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import { Link2 } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";

const auth = useAuthStore();
const loading = ref(true);
const accounts = ref([]);
const unlinking = ref(false);

const apiBase = (import.meta.env.VITE_API_URL ?? "/api").replace(/\/$/, "");

async function load() {
  loading.value = true;
  try {
    const res = await apiClient.get("/profile/social-accounts");
    accounts.value = res.data.data.accounts;
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not load connected accounts."));
  } finally {
    loading.value = false;
  }
}

const google = () => accounts.value.find((a) => a.provider === "google");

async function unlink() {
  if (!confirm("Unlink your Google account? You'll sign in with your password instead.")) return;
  unlinking.value = true;
  try {
    await apiClient.delete("/profile/social-accounts/google");
    toast.success("Google account unlinked.");
    await load();
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not unlink — set a password first if this account has none."));
  } finally {
    unlinking.value = false;
  }
}

onMounted(load);
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="flex items-center gap-2">
        <Link2 class="h-4 w-4 text-primary" /> Connected accounts
      </CardTitle>
    </CardHeader>
    <CardContent>
      <div v-if="loading" class="space-y-2">
        <Skeleton class="h-10 w-full" />
      </div>
      <div v-else class="flex items-center justify-between gap-3 rounded-md border border-border p-3">
        <div class="flex items-center gap-3">
          <svg class="h-5 w-5" viewBox="0 0 24 24"><path fill="#4285F4" d="M23.52 12.27c0-.85-.08-1.66-.22-2.44H12v4.62h6.47a5.53 5.53 0 0 1-2.4 3.63v3h3.88c2.27-2.09 3.57-5.17 3.57-8.81z"/><path fill="#34A853" d="M12 24c3.24 0 5.96-1.07 7.95-2.92l-3.88-3c-1.08.72-2.45 1.15-4.07 1.15-3.13 0-5.78-2.11-6.73-4.96H1.27v3.1A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.27 14.27a7.2 7.2 0 0 1 0-4.54v-3.1H1.27a12 12 0 0 0 0 10.74z"/><path fill="#EA4335" d="M12 4.75c1.76 0 3.34.6 4.59 1.79l3.44-3.44C17.95 1.19 15.24 0 12 0A12 12 0 0 0 1.27 6.63l4 3.1C6.22 6.86 8.87 4.75 12 4.75z"/></svg>
          <div>
            <p class="text-sm font-medium text-text-primary">Google</p>
            <p class="text-xs text-text-tertiary">{{ google() ? `Linked` : "Not linked" }}</p>
          </div>
        </div>
        <Button v-if="google()" variant="ghost" size="sm" :loading="unlinking" @click="unlink">Unlink</Button>
        <a
          v-else
          :href="`${apiBase}/auth/google/redirect`"
          class="rounded-pill border border-border-strong px-3 py-1.5 text-xs font-medium text-text-secondary hover:bg-black/5"
        >
          Connect
        </a>
      </div>
      <p v-if="!auth.user?.has_password" class="mt-2 text-xs text-text-tertiary">
        This account has no password set, so Google can't be unlinked until one is added.
      </p>
    </CardContent>
  </Card>
</template>

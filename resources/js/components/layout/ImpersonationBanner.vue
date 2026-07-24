<script setup>
import { computed, ref } from "vue";
import { useRouter } from "vue-router";
import { toast } from "vue-sonner";
import { Eye } from "@lucide/vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";

const auth = useAuthStore();
const router = useRouter();
const stopping = ref(false);

const expiresIn = computed(() => {
  if (!auth.impersonating?.expires_at) return "";
  const ms = new Date(auth.impersonating.expires_at).getTime() - Date.now();
  if (ms <= 0) return "expiring";
  return `${Math.max(1, Math.round(ms / 60000))} min left`;
});

async function stop() {
  stopping.value = true;
  try {
    await apiClient.post("/platform/impersonate/end");
  } catch {
    // Even if the server call fails (e.g. already expired), still restore
    // the platform session locally — staying stuck impersonating is worse.
  } finally {
    if (auth.platformToken) {
      // A fresh /me under the restored token repopulates the real user.
      try {
        const res = await apiClient.get("/me", { headers: { Authorization: `Bearer ${auth.platformToken}` } });
        auth.stopImpersonation(res.data.data);
      } catch {
        auth.logout();
      }
    } else {
      auth.logout();
    }
    stopping.value = false;
    toast.success("Impersonation ended.");
    router.push("/platform");
  }
}
</script>

<template>
  <div v-if="auth.impersonating" class="sticky top-0 z-50 flex items-center justify-center gap-3 bg-amber-500 px-4 py-2 text-sm font-medium text-black">
    <Eye class="h-4 w-4" />
    <span>Viewing as {{ auth.user?.name }} — {{ auth.impersonating.reason }} · read-only · {{ expiresIn }}</span>
    <button
      type="button"
      :disabled="stopping"
      @click="stop"
      class="ml-2 rounded-pill bg-black/10 px-3 py-1 text-xs font-semibold hover:bg-black/20 disabled:opacity-50"
    >
      Stop impersonating
    </button>
  </div>
</template>

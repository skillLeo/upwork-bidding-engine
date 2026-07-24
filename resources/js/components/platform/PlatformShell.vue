<script setup>
import { useRoute, useRouter } from "vue-router";
import { Activity, Building2, LogOut, SlidersHorizontal } from "@lucide/vue";
import { useAuthStore } from "@/stores/auth";
import { cn } from "@/lib/utils";

defineProps({ title: { type: String, required: true } });

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const navItems = [
  { to: "/platform", name: "platform-tenants", label: "Tenants", icon: Building2 },
  { to: "/platform/settings", name: "platform-settings", label: "Settings", icon: SlidersHorizontal },
  { to: "/platform/health", name: "platform-health", label: "Health", icon: Activity },
];

function backToApp() {
  router.push("/leads");
}
</script>

<template>
  <div class="min-h-screen bg-[#0B0E14]">
    <header class="border-b border-white/10 bg-[#11151F]">
      <div class="mx-auto flex h-14 max-w-[1200px] items-center justify-between px-4">
        <div class="flex items-center gap-4">
          <span class="rounded bg-amber-500/15 px-2 py-1 text-xs font-bold tracking-wide text-amber-400 uppercase">
            Platform console
          </span>
          <nav class="flex items-center gap-1">
            <router-link
              v-for="item in navItems"
              :key="item.name"
              :to="item.to"
              :class="
                cn(
                  'flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                  route.name === item.name || route.name?.startsWith(item.name)
                    ? 'bg-white/10 text-white'
                    : 'text-white/60 hover:bg-white/5 hover:text-white',
                )
              "
            >
              <component :is="item.icon" class="h-3.5 w-3.5" /> {{ item.label }}
            </router-link>
          </nav>
        </div>
        <div class="flex items-center gap-3 text-xs text-white/50">
          <span>{{ auth.user?.email }}</span>
          <button type="button" @click="backToApp" class="flex items-center gap-1 rounded-md px-2 py-1 hover:bg-white/5 hover:text-white">
            <LogOut class="h-3.5 w-3.5" /> Back to app
          </button>
        </div>
      </div>
    </header>
    <main class="mx-auto max-w-[1200px] px-4 py-6">
      <h1 class="mb-5 text-lg font-semibold text-white">{{ title }}</h1>
      <slot />
    </main>
  </div>
</template>

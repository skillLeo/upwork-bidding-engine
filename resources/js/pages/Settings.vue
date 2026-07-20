<script setup>
import { computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
  Activity,
  Bell,
  Bot,
  Brain,
  Mail as MailIcon,
  MessageCircle,
  Palette,
  SlidersHorizontal,
  Wallet,
  Webhook,
} from "@lucide/vue";
import { useSettings } from "@/composables/useSettings";
import PageContainer from "@/components/layout/PageContainer.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import BrandingSection from "@/components/settings/BrandingSection.vue";
import DiagnosticsSection from "@/components/settings/DiagnosticsSection.vue";
import VollnaSection from "@/components/settings/VollnaSection.vue";
import OpenClawSection from "@/components/settings/OpenClawSection.vue";
import AiApiSection from "@/components/settings/AiApiSection.vue";
import AiUsageSection from "@/components/settings/AiUsageSection.vue";
import WhatsAppSection from "@/components/settings/WhatsAppSection.vue";
import BrowserAlertsSection from "@/components/settings/BrowserAlertsSection.vue";
import MailSection from "@/components/settings/MailSection.vue";
import RulesSection from "@/components/settings/RulesSection.vue";
import { cn } from "@/lib/utils";

const { settings, isLoading, refetch } = useSettings();
const route = useRoute();
const router = useRouter();

const sections = [
  { id: "branding", label: "Branding", icon: Palette },
  { id: "diagnostics", label: "Diagnostics", icon: Activity },
  { id: "vollna", label: "Vollna", icon: Webhook },
  { id: "openclaw", label: "AI Engine", icon: Bot },
  { id: "ai", label: "AI Models & Prompts", icon: Brain },
  { id: "ai-usage", label: "AI Usage & Cost", icon: Wallet },
  { id: "whatsapp", label: "WhatsApp", icon: MessageCircle },
  { id: "browser-alerts", label: "Browser Alerts", icon: Bell },
  { id: "mail", label: "Mail", icon: MailIcon },
  { id: "rules", label: "Bidding Rules", icon: SlidersHorizontal },
];
const sectionIds = sections.map((s) => s.id);

// One section rendered at a time, like a real settings app (Stripe/GitHub/
// Linear) - not a single long page you jump-scroll around. The current tab
// lives in the URL so a refresh or a shared link lands back on the same
// section instead of always resetting to Branding.
const activeSection = computed(() =>
  sectionIds.includes(route.query.section) ? route.query.section : sections[0].id,
);

function selectSection(id) {
  if (id === activeSection.value) return;
  router.replace({ path: route.path, query: { ...route.query, section: id } });
}

const activeLabel = computed(() => sections.find((s) => s.id === activeSection.value)?.label ?? "");
</script>

<template>
  <PageContainer v-if="isLoading || !settings" class="max-w-[1600px]">
    <Skeleton class="mb-5 h-7 w-40" />
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[240px_1fr]">
      <Skeleton class="hidden h-64 w-full lg:block" />
      <Skeleton class="h-96 w-full" />
    </div>
  </PageContainer>

  <PageContainer v-else class="max-w-[1600px]">
    <div class="mb-5">
      <h1 class="text-xl font-semibold text-text-primary">Settings</h1>
      <p class="text-sm text-text-secondary">
        API keys, tokens, and bidding rules — nothing here is stored in .env.
      </p>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[240px_1fr] lg:items-start">
      <nav
        class="sticky top-16 z-10 -mx-4 overflow-x-auto bg-bg/95 px-4 py-2 backdrop-blur lg:top-20 lg:mx-0 lg:overflow-visible lg:bg-transparent lg:px-0 lg:py-0"
      >
        <div
          class="flex gap-1 border-b border-border pb-2 lg:flex-col lg:gap-0.5 lg:overflow-hidden lg:rounded-card lg:border lg:bg-surface lg:p-2 lg:pb-2 lg:shadow-card"
        >
          <button
            v-for="section in sections"
            :key="section.id"
            type="button"
            @click="selectSection(section.id)"
            :aria-current="activeSection === section.id ? 'page' : undefined"
            :class="
              cn(
                'flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium whitespace-nowrap transition-colors',
                activeSection === section.id
                  ? 'bg-primary-tint text-primary'
                  : 'text-text-secondary hover:bg-black/5 hover:text-text-primary',
              )
            "
          >
            <component :is="section.icon" class="h-4 w-4 shrink-0" :stroke-width="activeSection === section.id ? 2.25 : 1.75" />
            {{ section.label }}
          </button>
        </div>
      </nav>

      <div class="min-w-0">
        <h2 class="mb-3 text-base font-semibold text-text-primary lg:hidden">{{ activeLabel }}</h2>

        <Transition name="settings-fade" mode="out-in">
          <div :key="activeSection">
            <BrandingSection v-if="activeSection === 'branding'" :settings="settings.branding" @saved="refetch" />
            <DiagnosticsSection v-else-if="activeSection === 'diagnostics'" />
            <VollnaSection v-else-if="activeSection === 'vollna'" :settings="settings.vollna" @saved="refetch" />
            <OpenClawSection v-else-if="activeSection === 'openclaw'" :settings="settings.openclaw" @saved="refetch" />
            <AiApiSection v-else-if="activeSection === 'ai'" :settings="settings.ai" @saved="refetch" />
            <AiUsageSection v-else-if="activeSection === 'ai-usage'" />
            <WhatsAppSection v-else-if="activeSection === 'whatsapp'" :settings="settings.whatsapp" @saved="refetch" />
            <BrowserAlertsSection v-else-if="activeSection === 'browser-alerts'" />
            <MailSection v-else-if="activeSection === 'mail'" :settings="settings.mail" @saved="refetch" />
            <RulesSection v-else-if="activeSection === 'rules'" :settings="settings.rules" @saved="refetch" />
          </div>
        </Transition>
      </div>
    </div>
  </PageContainer>
</template>

<style scoped>
.settings-fade-enter-active,
.settings-fade-leave-active {
  transition: opacity 0.12s ease;
}
.settings-fade-enter-from,
.settings-fade-leave-to {
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .settings-fade-enter-active,
  .settings-fade-leave-active {
    transition: none;
  }
}
</style>

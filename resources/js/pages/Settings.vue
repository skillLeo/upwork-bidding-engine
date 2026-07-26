<script setup>
import { computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
  Activity,
  Bell,
  Bot,
  Brain,
  Building2,
  MessageCircle,
  Palette,
  ShieldCheck,
  SlidersHorizontal,
  Users,
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
import TrackRecordSection from "@/components/settings/TrackRecordSection.vue";
import AiUsageSection from "@/components/settings/AiUsageSection.vue";
import NotificationsSection from "@/components/settings/NotificationsSection.vue";
import RulesSection from "@/components/settings/RulesSection.vue";
import MembersSection from "@/components/settings/MembersSection.vue";
import RolesMatrixSection from "@/components/settings/RolesMatrixSection.vue";
import WorkspaceSection from "@/components/settings/WorkspaceSection.vue";
import { useAuthStore } from "@/stores/auth";
import { cn } from "@/lib/utils";

const { settings, isLoading, refetch } = useSettings();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

// `need` is the permission a tab requires; a tab with no `need` is visible to
// anyone who can open Settings. Tabs the user lacks permission for are HIDDEN
// (not shown disabled) — a control that can never work is never rendered.
//
// `internalOnly` is the same idea one level up (P8): the AI Engine tab drives
// the OpenClaw bridge, which is one physical WhatsApp session belonging to the
// company operating the platform. It is not a product feature and never
// appears for a customer workspace. The server refuses it too — this only
// decides what is worth rendering.
//
// Mail is absent entirely: SMTP is deployment infrastructure, not a
// workspace's setting, and moved to the platform console with the other
// platform-only keys.
const allSections = [
  // PLATFORM-OWNER ONLY. These five are the product's own machinery, not a
  // customer's configuration:
  //   branding     — the product's name and logo, identical everywhere
  //   diagnostics  — integration health and error detail for the platform's
  //                  own plumbing
  //   vollna       — the lead source: ONE subscription funds every workspace
  //   openclaw     — the internal AI bridge
  //   ai           — how proposals are written and what they may claim
  // A workspace owner runs their workspace (bidding rules, notifications,
  // members, filters, their own leads); they do not run the product.
  { id: "branding", label: "Branding", icon: Palette, platformOnly: true },
  { id: "diagnostics", label: "Diagnostics", icon: Activity, platformOnly: true },
  { id: "vollna", label: "Vollna", icon: Webhook, platformOnly: true },
  { id: "openclaw", label: "AI Engine", icon: Bot, platformOnly: true, internalOnly: true },
  { id: "ai", label: "Proposals & Facts", icon: Brain, platformOnly: true },
  // The workspace's OWN facts and signature. Separate from the tab above,
  // which is the methodology: hiding the methodology from a customer must not
  // also hide the data only that customer can supply, or their proposals stay
  // paused forever behind a banner pointing at a screen they cannot open.
  { id: "track-record", label: "Your track record", icon: Brain, need: "setting.project_facts" },
  { id: "ai-usage", label: "AI Usage & Cost", icon: Wallet, need: "ai_usage.view" },
  { id: "notifications", label: "Notifications", icon: Bell },
  { id: "rules", label: "Bidding Rules", icon: SlidersHorizontal },
  { id: "workspace", label: "Workspace", icon: Building2, need: "workspace.manage" },
  { id: "members", label: "Members", icon: Users, need: "members.invite" },
  { id: "roles", label: "Roles & permissions", icon: ShieldCheck },
];
const sections = computed(() =>
  allSections.filter(
    (s) =>
      (!s.need || auth.can(s.need)) &&
      (!s.internalOnly || auth.isInternalWorkspace) &&
      (!s.platformOnly || auth.isPlatformOwner),
  ),
);
const sectionIds = computed(() => sections.value.map((s) => s.id));

// One section rendered at a time, like a real settings app (Stripe/GitHub/
// Linear) - not a single long page you jump-scroll around. The current tab
// lives in the URL so a refresh or a shared link lands back on the same
// section instead of always resetting to Branding.
const activeSection = computed(() =>
  sectionIds.value.includes(route.query.section) ? route.query.section : sections.value[0].id,
);

function selectSection(id) {
  if (id === activeSection.value) return;
  router.replace({ path: route.path, query: { ...route.query, section: id } });
}

const activeLabel = computed(() => allSections.find((s) => s.id === activeSection.value)?.label ?? "");
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
        Your workspace: its integrations, bidding rules, members, and the facts your proposals
        are allowed to claim.
      </p>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[240px_1fr] lg:items-start">
      <nav class="sticky top-16 z-10 -mx-4 bg-bg/95 px-4 py-2 backdrop-blur lg:top-20 lg:mx-0 lg:bg-transparent lg:px-0 lg:py-0">
        <!-- Phone/tablet: a real dropdown, not a horizontal tab strip that
             cuts off mid-word with no hint there's more to scroll to. -->
        <select
          :value="activeSection"
          @change="selectSection($event.target.value)"
          class="h-11 w-full rounded-md border border-border-strong bg-white px-3 text-sm font-medium text-text-primary lg:hidden"
        >
          <option v-for="section in sections" :key="section.id" :value="section.id">
            {{ section.label }}
          </option>
        </select>

        <div
          class="hidden gap-0.5 lg:flex lg:flex-col lg:overflow-hidden lg:rounded-card lg:border lg:border-border lg:bg-surface lg:p-2 lg:pb-2 lg:shadow-card"
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
            <TrackRecordSection
              v-else-if="activeSection === 'track-record'"
              :settings="settings.ai"
              @saved="refetch"
            />
            <AiUsageSection v-else-if="activeSection === 'ai-usage'" />
            <NotificationsSection
              v-else-if="activeSection === 'notifications'"
              :settings="{ ...settings.rules, ...settings.whatsapp }"
              @saved="refetch"
            />
            <RulesSection v-else-if="activeSection === 'rules'" :settings="settings.rules" @saved="refetch" />
            <WorkspaceSection v-else-if="activeSection === 'workspace'" />
            <MembersSection v-else-if="activeSection === 'members'" />
            <RolesMatrixSection v-else-if="activeSection === 'roles'" />
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

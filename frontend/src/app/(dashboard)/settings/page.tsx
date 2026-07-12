"use client";

import { useSettings } from "@/lib/hooks/useSettings";
import { AuthGuard } from "@/components/layout/AuthGuard";
import { PageContainer } from "@/components/layout/Rails";
import { Skeleton } from "@/components/ui/Skeleton";
import { AiSection } from "@/components/settings/AiSection";
import { VollnaSection } from "@/components/settings/VollnaSection";
import { OpenClawSection } from "@/components/settings/OpenClawSection";
import { WhatsAppSection } from "@/components/settings/WhatsAppSection";
import { RulesSection } from "@/components/settings/RulesSection";

function SettingsContent() {
  const { settings, isLoading, mutate } = useSettings();

  if (isLoading || !settings) {
    return (
      <PageContainer className="max-w-[760px] space-y-4">
        <Skeleton className="h-7 w-40" />
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-48 w-full" />
        ))}
      </PageContainer>
    );
  }

  return (
    <PageContainer className="max-w-[760px] space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-text-primary">Settings</h1>
        <p className="text-sm text-text-secondary">
          API keys, tokens, and bidding rules — nothing here is stored in .env.
        </p>
      </div>
      <AiSection settings={settings.ai} onSaved={() => mutate()} />
      <VollnaSection settings={settings.vollna} onSaved={() => mutate()} />
      <OpenClawSection settings={settings.openclaw} onSaved={() => mutate()} />
      <WhatsAppSection settings={settings.whatsapp} onSaved={() => mutate()} />
      <RulesSection settings={settings.rules} onSaved={() => mutate()} />
    </PageContainer>
  );
}

export default function SettingsPage() {
  return (
    <AuthGuard adminOnly>
      <SettingsContent />
    </AuthGuard>
  );
}

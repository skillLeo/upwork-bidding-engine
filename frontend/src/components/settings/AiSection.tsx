"use client";

import * as React from "react";
import { toast } from "sonner";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { SecretField } from "@/components/ui/SecretField";
import { Label, FieldHint } from "@/components/ui/Input";
import { TestConnectionButton } from "./TestConnectionButton";
import { saveSettings } from "@/lib/hooks/useSettings";
import { apiErrorMessage } from "@/lib/api-client";
import type { SettingsResponse } from "@/lib/types";

const MODEL_SUGGESTIONS = [
  "claude-sonnet-4-6",
  "claude-opus-4-6",
  "claude-haiku-4-5",
  "claude-sonnet-4-5",
  "claude-opus-4-1",
];

export function AiSection({
  settings,
  onSaved,
}: {
  settings: SettingsResponse["ai"];
  onSaved: () => void;
}) {
  const [apiKey, setApiKey] = React.useState("");
  const [model, setModel] = React.useState(settings.claude_model);
  const [saving, setSaving] = React.useState(false);

  // Re-sync local state when `settings` changes (after a save/refetch) without
  // an Effect — setState during render is React's documented pattern for this.
  const [syncedModel, setSyncedModel] = React.useState(settings.claude_model);
  if (settings.claude_model !== syncedModel) {
    setSyncedModel(settings.claude_model);
    setModel(settings.claude_model);
  }

  async function handleSave() {
    setSaving(true);
    try {
      await saveSettings({ claude_api_key: apiKey, claude_model: model });
      setApiKey("");
      toast.success("AI settings saved.");
      onSaved();
    } catch (error) {
      toast.error(apiErrorMessage(error, "Could not save AI settings."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>AI (Claude)</CardTitle>
        <TestConnectionButton service="claude" />
      </CardHeader>
      <CardContent className="space-y-4">
        <SecretField
          testId="claude-api-key-input"
          label="Claude API key"
          isSet={settings.claude_api_key.is_set}
          masked={settings.claude_api_key.masked}
          value={apiKey}
          onChange={setApiKey}
          hint="Passed to OpenClaw with every scoring/drafting request — never stored in .env."
        />
        <div>
          <Label htmlFor="claude_model">Claude model</Label>
          <input
            id="claude_model"
            list="claude-model-options"
            value={model}
            onChange={(e) => setModel(e.target.value)}
            className="h-10 w-full rounded-md border border-border-strong bg-white px-3 text-sm text-text-primary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
          />
          <datalist id="claude-model-options">
            {MODEL_SUGGESTIONS.map((m) => (
              <option key={m} value={m} />
            ))}
          </datalist>
          <FieldHint>Pick a suggestion or type any current model ID.</FieldHint>
        </div>
        <div className="flex justify-end">
          <Button onClick={handleSave} loading={saving}>
            Save AI settings
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

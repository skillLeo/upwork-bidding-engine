"use client";

import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import type { AnalyticsJobType } from "@/lib/types";
import { CHART_CHROME } from "@/lib/chart-colors";
import { EmptyState } from "@/components/ui/EmptyState";
import { BarChart3 } from "lucide-react";

export function BestJobTypesChart({ data }: { data: AnalyticsJobType[] }) {
  if (data.length === 0) {
    return <EmptyState icon={BarChart3} title="Not enough scored leads yet" />;
  }

  return (
    <ResponsiveContainer width="100%" height={Math.max(180, data.length * 38)}>
      <BarChart data={data} layout="vertical" margin={{ top: 4, right: 28, left: 8, bottom: 4 }}>
        <CartesianGrid stroke={CHART_CHROME.grid} horizontal={false} />
        <XAxis
          type="number"
          allowDecimals={false}
          tick={{ fontSize: 11, fill: CHART_CHROME.axis }}
          axisLine={false}
          tickLine={false}
        />
        <YAxis
          type="category"
          dataKey="keyword"
          width={100}
          tick={{ fontSize: 12, fill: CHART_CHROME.textPrimary }}
          axisLine={false}
          tickLine={false}
        />
        <Tooltip
          cursor={{ fill: "rgba(10,102,194,0.06)" }}
          contentStyle={{ borderRadius: 8, border: `1px solid ${CHART_CHROME.border}`, fontSize: 12 }}
          formatter={(value) => [`${value} lead${value === 1 ? "" : "s"}`, "Matching brief"]}
        />
        <Bar dataKey="count" fill="#0A66C2" radius={[0, 4, 4, 0]} barSize={16} />
      </BarChart>
    </ResponsiveContainer>
  );
}

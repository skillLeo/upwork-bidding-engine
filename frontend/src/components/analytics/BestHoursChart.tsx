"use client";

import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import type { AnalyticsHour } from "@/lib/types";
import { CHART_CHROME } from "@/lib/chart-colors";

function formatHour(hour: number): string {
  const period = hour < 12 ? "am" : "pm";
  const displayHour = hour % 12 === 0 ? 12 : hour % 12;
  return `${displayHour}${period}`;
}

export function BestHoursChart({ data }: { data: AnalyticsHour[] }) {
  const formatted = data.map((d) => ({ ...d, label: formatHour(d.hour) }));

  return (
    <ResponsiveContainer width="100%" height={220}>
      <BarChart data={formatted} margin={{ top: 8, right: 8, left: -16, bottom: 0 }}>
        <CartesianGrid stroke={CHART_CHROME.grid} vertical={false} />
        <XAxis
          dataKey="label"
          tick={{ fontSize: 10, fill: CHART_CHROME.axis }}
          axisLine={{ stroke: CHART_CHROME.grid }}
          tickLine={false}
          interval={2}
        />
        <YAxis
          allowDecimals={false}
          tick={{ fontSize: 11, fill: CHART_CHROME.axis }}
          axisLine={false}
          tickLine={false}
          width={28}
        />
        <Tooltip
          cursor={{ fill: "rgba(10,102,194,0.06)" }}
          contentStyle={{ borderRadius: 8, border: `1px solid ${CHART_CHROME.border}`, fontSize: 12 }}
          formatter={(value) => [`${value} job${value === 1 ? "" : "s"} posted`, ""]}
        />
        <Bar dataKey="count" fill="#0A66C2" radius={[4, 4, 0, 0]} maxBarSize={18} />
      </BarChart>
    </ResponsiveContainer>
  );
}

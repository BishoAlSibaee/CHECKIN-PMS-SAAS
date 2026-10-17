export interface PeakDay {
  id: string;
  day: string;
  enabled: boolean;
  multiplier: number;
}

export interface PeakMonth {
  id: string;
  month: string;
  enabled: boolean;
  multiplier: number;
}

export interface PeakConfig {
  peakDays: PeakDay[];
  peakMonths: PeakMonth[];
}

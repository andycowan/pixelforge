export type CreditBalance = {
    available_usd: string;
    held_usd: string;
};

export type CreditPackage = {
    id: string;
    amount_usd: string;
};

export type CreditEntry = {
    id: number;
    type:
        | 'purchase'
        | 'hold'
        | 'release'
        | 'debit'
        | 'credit_adjustment'
        | 'debit_adjustment';
    amount_usd: string;
    generation_id: string | null;
    detail: string | null;
    created_at: string;
};

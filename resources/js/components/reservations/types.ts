export type Settings = {
    price_cents_per_person_per_hour: number;
    min_group_size: number;
    max_group_size: number;
    min_duration_minutes: number;
    max_duration_minutes: number;
    opening_time: string;
    closing_time: string;
};

export type TimeRange = {
    id: number;
    starts_at: string;
    ends_at: string;
};

export type Participant = {
    id: number;
    name: string;
};

export type ParticipantStatus = 'invited' | 'accepted' | 'declined';

export type Invitee = Participant & { status: ParticipantStatus };

export type Invitation = TimeRange & {
    group_size: number;
    owner_name: string;
};

export type MyReservation = TimeRange & {
    group_size: number;
    price_cents: number;
    status: 'pending' | 'active' | 'cancelled';
    is_owner: boolean;
    participants: Invitee[];
};

// Only people who accepted take one of the paid seats.
export const acceptedCount = (reservation: MyReservation) =>
    reservation.participants.filter(
        (participant) => participant.status === 'accepted',
    ).length;

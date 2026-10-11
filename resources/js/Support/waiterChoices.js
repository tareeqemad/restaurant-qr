export function requiresChoices(item) {
    return (item?.modifier_groups ?? []).some((group) =>
        Boolean(group.required) || Number(group.min_select) > 0,
    );
}

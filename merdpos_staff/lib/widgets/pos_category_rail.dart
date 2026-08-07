part of merdpos_staff;

class PosCategoryRail extends StatelessWidget {
  const PosCategoryRail({
    super.key,
    required this.categories,
    required this.selectedCategory,
    required this.onSelected,
  });

  final List<String> categories;
  final String? selectedCategory;
  final ValueChanged<String?> onSelected;

  @override
  Widget build(BuildContext context) {
    final List<String?> items = <String?>[null, ...categories];
    return ColoredBox(
      color: Colors.white,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          const Padding(
            padding: EdgeInsets.fromLTRB(14, 20, 14, 12),
            child: Text(
              'CATEGORIES',
              style: TextStyle(
                color: _PosColors.muted,
                fontSize: 12,
                fontWeight: FontWeight.w800,
                letterSpacing: 1.1,
              ),
            ),
          ),
          Expanded(
            child: ListView.builder(
              key: const Key('category-rail'),
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 16),
              itemCount: items.length,
              itemBuilder: (context, index) {
                final String? category = items[index];
                final bool selected = category == selectedCategory;
                return Padding(
                  padding: const EdgeInsets.only(bottom: 5),
                  child: Material(
                    color: selected ? _PosColors.brandSoft : Colors.transparent,
                    borderRadius: BorderRadius.circular(10),
                    child: InkWell(
                      key: Key(
                        category == null
                            ? 'category-all'
                            : 'category-$category',
                      ),
                      onTap: () => onSelected(category),
                      borderRadius: BorderRadius.circular(10),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 12,
                          vertical: 14,
                        ),
                        child: Text(
                          category ?? 'All',
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: selected ? _PosColors.brand : _PosColors.ink,
                            fontWeight: selected
                                ? FontWeight.w800
                                : FontWeight.w600,
                          ),
                        ),
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

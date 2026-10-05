<?php
/**
 * Curated collections: themed views over the catalogue.
 *
 * Why collections and not new categories: Sikh Literature is 2,545 books in one
 * bucket, and re-categorising 5,781 rows would change every existing category URL
 * and author page. Collections are additive, so nothing that already works moves.
 *
 * Each entry carries:
 *   label    chip, nav and heading text
 *   blurb    one line
 *   intro    a paragraph of context, so a collection page teaches as well as lists
 *   terms    matched against Title, Subject and Author, Punjabi and English,
 *            because Archive.org metadata is inconsistent about which script it
 *            records
 *   related  sibling collections, so a reader who came for martyrs can reach
 *            history without going back to the index
 *   people   figures to surface from the catalogue's own author index
 *   refs     verified external references
 *
 * Counted by books, so a Gutka is both a Gurdwara publication and Sikh Gurmat.
 * Reference URLs were checked (HTTP 200) rather than assumed; see
 * tools/collection-counts.php for the size of each before it is rendered.
 */

function collections(): array
{
    $w = 'https://en.wikipedia.org/wiki/';
    return [

    'guru-granth-sahib' => [
        'label' => 'Guru Granth Sahib',
        'blurb' => 'The eternal Guru, and the writings that make it.',
        'intro' => 'The Guru Granth Sahib is the eternal Guru of the Sikh faith and the '
            . 'central scripture of Sikhism. It carries 1,431 verses across 31 ragis, '
            . 'composed by the ten Gurus from Guru Nanak to Guru Gobind Singh. Guru Gobind '
            . 'Singh completed the Adi Granth in 1604 at Goindiwala and gave it its final '
            . 'status, declaring it the eternal Guru in place of his own person. No '
            . 'Guru after him has added to it, and it is treated as living and '
            . 'breathing rather than as a closed text. This collection holds the scripture '
            . 'itself alongside commentaries, translations and studies of it.',
        'terms' => ['ਗੁਰੂ ਗ੍ਰੰਥ', 'ਗੁਰੂਗ੍ਰੰਥ', 'ਅੰਗਦ', 'ਆਦਿ ਗ੍ਰੰਥ', 'ਸ੍ਰੀ ਗੁਰੂ ਗ੍ਰੰਥ',
                    'guru granth', 'guru granth sahib', 'sggs', 'ad granth', 'angad', 'benedict'],
        'related' => ['dasam-granth', 'gurmukhi', 'gutkas', 'prayer', 'saints'],
        'people' => ['Guru Nanak', 'Guru Gobind Singh', 'Guru Angad'],
        'refs' => [[$w . 'Guru_Granth_Sahib', 'Guru Granth Sahib'],
                   [$w . 'Gurbani', 'Gurbani'],
                   [$w . 'Sikh_Gurus', 'The ten Gurus'],
                   [$w . 'Gurdwara', 'Gurdwara: where it is read']],
    ],

    'dasam-granth' => [
        'label' => 'Dasam Granth',
        'blurb' => 'The second canon: Zafarnama, Amrit Bani, Charitropakhyan.',
        'intro' => 'The Dasam Granth is the second body of Sikh scripture, attributed to '
            . 'Guru Gobind Singh and comprising eleven works. The best known is the '
            . 'Zafarnama, a letter in Persian verse written to the Mughal emperor '
            . 'Aurangzeb from the siege of Anandpur in 1705, finished later at Hazur Sahib. '
            . 'Others include Amrit Bani and Charitropakhyan. Its status is debated among '
            . 'scholars, which makes it a subject of genuine study rather than settled '
            . 'doctrine. This collection holds the texts and the literature written about them.',
        'terms' => ['ਦਸਮ', 'ਦਸਮਗ੍ਰੰਥ', 'ਜਾਫ਼ਰਨਾਮਾ', 'ਜਫਰਨਾਮਾ', 'ਅਮ੍ਰਿਤ ਬਾਣੀ',
                    'ਚੰਦੀਗ੍ਰੰਥ', 'ਕਥਾ ਕਰਮ', 'ਕੀਤਾ ਕਰਮ', 'ਜਸ ਮਹਾ',
                    'dasam', 'zafarnama', 'zafarnaama', 'amrit bani', 'charitropakhyan',
                    'chandi di var', 'jassa'],
        'related' => ['guru-granth-sahib', 'martyrs', 'saints', 'gurmukhi'],
        'people' => ['Guru Gobind Singh', 'Amar Bharti', 'Dr. Amar Singh'],
        'refs' => [[$w . 'Dasam_Granth', 'Dasam Granth'],
                   [$w . 'Zafarnama', 'Zafarnama'],
                   [$w . 'Charitropakhyan', 'Charitropakhyan'],
                   [$w . 'Guru_Gobind_Singh', 'Guru Gobind Singh']],
    ],

    'gutkas' => [
        'label' => 'Gutkas',
        'blurb' => 'Daily banis for the home and the Gurdwara.',
        'intro' => 'A Gutka is a printed selection of banis, ordered so that a whole day '
            . 'can be read from it - typically an opening, Japji Sahib, a Chaupai, an '
            . 'arasa and an evening shabad. Gutkas are the everyday practice of the '
            . 'Sardhani sangat, and their period and variant notes are themselves a record '
            . 'of how the scripture is sung. This collection gathers Gutkas and similar '
            . 'daily recitations.',
        'terms' => ['ਗੁਤਕਾ', 'ਗੁਤਕੇ', 'ਗੁਰਬਾਣੀ ਸੰਗ੍ਰਹ', 'ਸੰਖੀ',
                    'gutka', 'gurtak', 'vaar'],
        'related' => ['gurmukhi', 'prayer', 'guru-granth-sahib'],
        'people' => [],
        'refs' => [[$w . 'Gurbani', 'Gurbani'], [$w . 'Kirtan', 'Kirtan'], [$w . 'Gurdwara', 'Gurdwara']],
    ],

    'gurmukhi' => [
        'label' => 'Sikh Gurmat',
        'blurb' => 'Scripture, grammar and the tradition of reading it.',
        'intro' => 'Gurmat is the body of Sikh scripture, and it is a written tradition as '
            . 'much as a sung one: the script is Gurmukhi, the language a living Punjabi, and '
            . 'the tradition of interpretation is written down in works on grammar, '
            . 'phonetics, rhetoric and scripture history. This collection holds scripture '
            . 'alongside the scholarship that reads it.',
        'terms' => ['ਗੁਰਮੁਖੀ', 'ਗੁਰਮੁਖ', 'ਗੁਰਬਾਣੀ', 'ਬਾਣੀ', 'ਕੀਤਾਬ',
                    'gurmukhi', 'gurmukh', 'gurbani', 'kirtan', 'shabad', 'bani'],
        'related' => ['guru-granth-sahib', 'viakhiya', 'learning', 'poetry'],
        'people' => [],
        'refs' => [[$w . 'Gurbani', 'Gurbani'], [$w . 'Kirtan', 'Kirtan'],
                   [$w . 'Shabad', 'Shabad'], [$w . 'Punjabi_University', 'Punjabi University, Patiala']],
    ],

    'martyrs' => [
        'label' => 'Sikh Martyrs',
        'blurb' => 'Those who gave their lives for the faith.',
        'intro' => 'Sikh history is threaded with deliberate martyrdom, understood as '
            . 'willing sacrifice rather than misfortune. Bhai Taru Singh of Tarn Taran was '
            . 'sawn alive on the orders of the Mughal authorities in 1717, aged thirteen. '
            . 'All four of Guru Gobind Singh\'s sons were executed, and the execution of '
            . 'Bhai Mati Das, Bhai Sahiba and Bhai Dayala on a single day in 1739 is '
            . 'remembered at every Gurpurab. The literature of the martyrs is a major part '
            . 'of Punjabi writing.',
        'terms' => ['ਸ਼ਹੀਦ', 'ਸ਼ਹੀਦੀ', 'ਬਲੀਦ', 'ਕੁਰਬਾਨੀ', 'ਸ਼ਹੀਦੀਆਂ', 'ਸ਼ਹੀਦੀਆਂ ਦੀ',
                    'martyr', 'martyrs', 'shaheed', 'sacrifice', 'qurbani'],
        'related' => ['history', 'martial', 'dasam-granth', 'freedom-fighters'],
        'people' => ['Bhai Jodh Singh', 'Bhai Randhir Singh', 'Bhai Guriqbal Singh', 'Darshan Singh'],
        'refs' => [[$w . 'Bhai_Taru_Singh', 'Bhai Taru Singh'],
                   [$w . 'Sikh_history', 'Sikh history'],
                   [$w . 'Aurangzeb', 'Aurangzeb, under whose orders they died']],
    ],

    'saints' => [
        'label' => 'Saints and Sages',
        'blurb' => 'Sufis, Bhagats and the Sikh preceptors.',
        'intro' => 'Sikh tradition sets Guru Nanak among a line of earlier saints and '
            . 'sages, and the Dasam Granth carries whole panegyrics to them: Baba Farid '
            . 'and Shah Husain, Kabir and Namdev, Raidas and Parmanand. Their verses are '
            . 'sung in the Gurdwara alongside the Guru Granth Sabib. This collection holds '
            . 'their lives, their poetry and the writing about them.',
        'terms' => ['ਸੂਫੀ', 'ਭਗਤ', 'ਸੰਤ ਜੀ', 'ਬਾਬਾ', 'ਵਿਚਾਰਕ', 'ਉਪਦੇਸ਼ਕ',
                    'ਕਬੀਰ', 'ਨੰਦ ਲਾਰਡ', 'ਫਰੀਦ', 'ਸਰਬ ਲਾਰਡ', 'ਰਾਮ ਦਾਸ', 'ਹਰਿ ਰਾਏ',
                    'ਮਸਿਕ ਪ੍ਰਤੀਮ', 'ਗੋਬਿੰਦ ਸਿੰਘ', 'ਤੇਗ ਬਹਾਦੁਰ', 'ਗੁਰਮਾਂ ਤੇਗ',
                    'sufi', 'bhagat', 'seer', 'saint ', 'saints ',
                    'kabir', 'gobind singh', 'guru nanak dev', 'nanak dev',
                    'maharaja ranjit', 'maharaja', 'guru angad', 'guru amar das'],
        'related' => ['gurmukhi', 'biographies', 'guru-granth-sahib', 'history'],
        'people' => ['Guru Nanak', 'Baba Farid'],
        'refs' => [[$w . 'Baba_Farid', 'Baba Farid'], [$w . 'Kabir', 'Kabir'],
                   [$w . 'Guru_Nanak', 'Guru Nanak'], [$w . 'Guru_Angad', 'Guru Angad']],
    ],

    'freedom-fighters' => [
        'label' => 'Freedom Fighters',
        'blurb' => 'The liberation movement.',
        'intro' => 'The later struggle for a Sikh homeland, from the Akali movement and '
            . 'the gurdwara reforms through the campaigns of the 1920s and the '
            . 'independence movement. Much of it is documented in newspapers, pamphlets '
            . 'and oral testimony, which is why this section leans on periodicals and '
            . 'biographies.',
        'terms' => ['ਸੱਭਰਣ', 'ਕਿਸਾਨ', 'ਆਜ਼ਾਦ', 'ਲੋਕ', 'ਸਰਦਾਰ',
                    'freedom', 'liberation', 'sarbharm', 'kisan'],
        'related' => ['martyrs', 'history', 'periodicals', 'biographies'],
        'people' => [],
        'refs' => [[$w . 'Sikh_history', 'Sikh history'], [$w . 'Sikhism', 'Sikhism']],
    ],

    'martial' => [
        'label' => 'Martial Traditions',
        'blurb' => 'Gatka, Nihang and the warrior ethos.',
        'intro' => 'Gatka is the martial art of the Khalsa, systematised by Guru Gobind '
            . 'Singh alongside the Khalsa itself in 1699. Its practice is inseparable from '
            . 'the Nihang order and from the seasonal Gatka celebrations at the '
            . 'gurdwaras. NOTE: this catalogue holds too few texts to render as a section.',
        'terms' => ['ਕੌਲ', 'ਗਤਕਾ', 'ਨਿਹੰਗ', 'ਸਿੱਖੀ', 'ਲੜਾਈ',
                    'gatka', 'martial', 'warrior', 'nihang', 'sikh warrior'],
        'related' => ['martyrs', 'dasam-granth', 'history'],
        'people' => [],
        'refs' => [[$w . 'Gatka', 'Gatka'], [$w . 'Nihang', 'Nihang'], [$w . 'Khalsa', 'Khalsa']],
    ],

    'viakhiya' => [
        'label' => 'Gurmat Principles',
        'blurb' => 'What the Guru taught, and how it is read.',
        'intro' => 'The doctrinal core of Sikh teaching - the unity of God, the equality of '
            . 'all people, the rejection of caste and of ritual for its own sake, and the '
            . 'necessity of honest work and shared community. Written expositions of these '
            . 'principles, rather than the scripture itself. NOTE: too few holdings to '
            . 'render as a section yet.',
        'terms' => ['ਵਿਆਖਿਆ', 'ਸਿੱਖਾ', 'ਸਿਦਕਾਂਤ', 'ਤੱਤ', 'ਪ੍ਰਬੰਧ',
                    'viakhia', 'principle', 'philosophy', 'sikh philosophy', 'precept'],
        'related' => ['gurmukhi', 'theology', 'rehat-maryada'],
        'people' => [],
        'refs' => [[$w . 'Sikhism', 'Sikhism'], [$w . 'Sikh_Gurus', 'The ten Gurus']],
    ],

    'theology' => [
        'label' => 'Gurmat Theology',
        'blurb' => 'Vishkaram, Nirgun and Rahu.',
        'intro' => 'The conceptual vocabulary of Sikh theology, from the nirgun and sagu '
            . 'description of the Divine to the debates around vishkaram and the '
            . 'interpretation of scripture. NOTE: this catalogue holds too few texts on the '
            . 'topic to render as a section.',
        'terms' => ['ਵਿਸ਼ਕਰਮ', 'ਨਿਰਗੁਣ', 'ਰਹੁ', 'ਤਤਤਵਰ', 'ਵਿਸ਼ਕਰਮੀ',
                    'theology', 'vishkaram', 'nirgun', 'rahu', 'tatvadar'],
        'related' => ['viakhiya', 'gurmukhi', 'rehat-maryada'],
        'people' => [],
        'refs' => [[$w . 'Sikhism', 'Sikhism']],
    ],

    'rehat-maryada' => [
        'label' => 'Rehat Maryada',
        'blurb' => 'Living by the Guru\'s discipline.',
        'intro' => 'The code of conduct prescribed at the Khalsa initiation, gathered in '
            . 'the Rehat Maryada: the five Ks, honest labour, sharing in the community, and '
            . 'the raising of children in the faith. It is the practical expression of what '
            . 'the Guru taught, and it is administered in the gurdwara.',
        'terms' => ['ਰਹਤ', 'ਮਰਜਾਦ', 'ਵਿਆਖਿਆ ਸੰਗ੍ਰਹ',
                    'rehat', 'maryada', 'discipline'],
        'related' => ['martial', 'viakhiya', 'prayer', 'gurmukhi'],
        'people' => [],
        'refs' => [[$w . 'Rehat_Maryada', 'Rehat Maryada'], [$w . 'Amrit_Sanchar', 'Amrit Sanchar'],
                   [$w . 'Khalsa', 'Khalsa']],
    ],

    'poetry' => [
        'label' => 'Poetry',
        'blurb' => 'Kavita, kirtan and modern verse.',
        'intro' => 'Punjabi verse from the medieval kavitt to the modern poets: the kirtan '
            . 'of the Gurus, the shabads of the saints, and twentieth-century poetry that '
            . 'carried the language through Partition and after.',
        'terms' => ['ਕਵਿਤਾ', 'ਕੁਹਾਣੀ', 'ਗੀਤ', 'ਸ਼ਬਦ',
                    'poetry', 'kavita', 'kahani', 'verse', 'poem'],
        'related' => ['gurmukhi', 'biographies', 'history'],
        'people' => ['Pritam Singh Kavi', 'Jagjit Singh Dr.'],
        'refs' => [[$w . 'Kirtan', 'Kirtan'], [$w . 'Gurbani', 'Gurbani']],
    ],

    'history' => [
        'label' => 'History',
        'blurb' => 'Punjab and the Sikh world, chronicle by chronicle.',
        'intro' => 'Punjab has been a crossroads between the Delhi Sultanate, the Mughals, '
            . 'the Afghan rulers and the Sikh Empire, and its history is unusually well '
            . 'documented in Persian, Punjabi and English sources. This section holds '
            . 'chronicles, gazetteers, memoirs and the modern histories built on them.',
        'terms' => ['ਇਤਿਹਾਸ', 'ਤਰੀਖ', 'ਰੀਤ', 'ਇਤਿਹਾਸਕ',
                    'history', 'historical', 'itihas', 'tareekh', 'chronicle'],
        'related' => ['martyrs', 'freedom-fighters', 'biographies', 'periodicals'],
        'people' => ['Gyan Singh', 'Darshan Singh'],
        'refs' => [[$w . 'Sikh_history', 'Sikh history'], [$w . 'Mughal_empire', 'Mughal Empire'],
                   [$w . 'Punjabi_University', 'Punjabi University, Patiala']],
    ],

    'encyclopedia' => [
        'label' => 'Encyclopedia and Mahan Kosh',
        'blurb' => 'Reference volumes.',
        'intro' => 'The Mahan Kosh, the encyclopaedia of Sikh literature compiled at '
            . 'Punjabi University Patiala and still in progress, is the standard reference '
            . 'for Punjabi literary history. This section holds reference volumes and '
            . 'encyclopaedias of Sikh and Punjabi studies.',
        'terms' => ['ਮਹਾਨ ਕੋਸ਼', 'ਐਨਕਾਈਸਕੋਪੀਡੀਆ', 'ਵਿਸ਼ਕੋਸ਼', 'ਕੋਸ਼',
                    'mahan kosh', 'mahankosh', 'encyclopedia', 'encyclopaedia',
                    'vishwakosh', 'punjabi vishav kosh'],
        'related' => ['dictionaries', 'history', 'biographies'],
        'people' => [],
        'refs' => [[$w . 'Mahan_Kosh', 'Mahan Kosh'],
                   [$w . 'Punjabi_University', 'Punjabi University, Patiala']],
    ],

    'dictionaries' => [
        'label' => 'Dictionaries',
        'blurb' => 'Lakhs and kosh.',
        'intro' => 'Punjabi lexicography: the Mahan Kosh and its abridged editions, '
            . 'bilingual dictionaries and the older lexical traditions. Useful for reading '
            . 'older scripture and poetry, where the vocabulary has moved on.',
        'terms' => ['ਲੁਘਤ', 'ਲੁਘਤ', 'ਸ਼ਬਦਕੋਸ਼', 'ਅਭਿਧਾਨ', 'ਕੋਸ਼',
                    'dictionary', 'dictionaries', 'lughat', 'shabdkosh', 'kosh', 'abhidhan'],
        'related' => ['encyclopedia', 'learning', 'gurmukhi'],
        'people' => [],
        'refs' => [[$w . 'Mahan_Kosh', 'Mahan Kosh'],
                   [$w . 'Punjabi_University', 'Punjabi University, Patiala']],
    ],

    'prayer' => [
        'label' => 'Prayer and Worship',
        'blurb' => 'Ardas, nit-nam and daily remembrance.',
        'intro' => 'The Ardas, the composition of the Khalsa that opens and closes almost '
            . 'every gathering, and nit-nam, the daily remembrance of the Name that structures '
            . 'Sikh practice. This section holds the prayers, the daily liturgy and writing '
            . 'about them.',
        'terms' => ['ਅਰਦਾਸ', 'ਨਿਤਨਾਮ', 'ਵੰਨ', 'ਭਜਨ', 'ਪ੍ਰਾਰਥਨਾ',
                    'ardas', 'nit nam', 'nitnam', 'prayer', 'worship', 'bhajan'],
        'related' => ['gutkas', 'rehat-maryada', 'gurmukhi', 'guru-granth-sahib'],
        'people' => [],
        'refs' => [[$w . 'Amrit_Sanchar', 'Amrit Sanchar'], [$w . 'Gurdwara', 'Gurdwara'],
                   [$w . 'Kirtan', 'Kirtan']],
    ],

    'vedic' => [
        'label' => 'Vedic Gurmat',
        'blurb' => 'The Vedic tradition read through Sikh thought.',
        'intro' => 'The relationship between the Sikh scriptural tradition and the wider '
            . 'Vedic and Sanskrit inheritance. NOTE: too few holdings in this catalogue to '
            . 'render as a section yet.',
        'terms' => ['ਵੇਦ', 'ਵੈਦਿਕ', 'ਸਮ੍ਰਤ', 'ਉਪਨਿਸ਼ਦ',
                    'vedic', 'veda', 'upanishad', 'samskrit'],
        'related' => ['viakhiya', 'gurmukhi', 'saints'],
        'people' => [],
        'refs' => [[$w . 'Sikhism', 'Sikhism']],
    ],

    'children' => [
        'label' => 'For Children',
        'blurb' => 'Reading for younger readers.',
        'intro' => 'Children\'s Punjabi: primers, story books and teaching material. '
            . 'NOTE: this catalogue holds too few to render as a section yet - but it is '
            . 'the clearest gap, since sikhbookclub.com carries a whole children\'s section.',
        'terms' => ['ਬੱਚ', 'ਬੱਚਾਂ', 'ਬਾਲ', 'ਕਿਸਾਨਾਂ ਲਈ', 'ਪੜ੍ਹਾਈ',
                    'children', "children's", 'kids', "kid's", 'juvenile', 'grade book'],
        'related' => ['learning', 'poetry', 'periodicals'],
        'people' => [],
        'refs' => [],
    ],

    'comics' => [
        'label' => 'Comics and Graphic',
        'blurb' => 'Punjabi in pictures.',
        'intro' => 'Punjabi comics and graphic narrative. NOTE: effectively absent from '
            . 'this catalogue - one holding. This is a genuine sourcing gap rather than a '
            . 'matching failure.',
        'terms' => ['ਕਾਰਟੂਨ', 'ਕਿਰਦਾਰ', 'ਚਿੱਤਰਕਾਥਾ',
                    'comic', 'comics', 'graphic novel', 'cartoon'],
        'related' => ['children', 'periodicals'],
        'people' => [],
        'refs' => [],
    ],

    'periodicals' => [
        'label' => 'Journals and Periodicals',
        'blurb' => 'Magazines, newspapers and bulletins.',
        'intro' => 'The periodical record: literary magazines, diaspora newspapers, '
            . 'gurdwara bulletins and community journals. For Punjabi studies these are often '
            . 'the only contemporary source for a period, and many issues here are single '
            . 'surviving copies.',
        'terms' => ['ਰਸਾਲਾ', 'ਮੈਗਾਜ਼ੀਨ', 'ਅਖਬਾਰ', 'ਬੁਲਿਤਨ', 'ਪ੍ਰਕਾਸਨ',
                    'magazine', 'journal', 'periodical', 'newspaper', 'bulletin', 'darpan'],
        'related' => ['history', 'freedom-fighters', 'biographies'],
        'people' => ['Jagdish Singh Waryam', 'www.DiscoverSikhism.com', 'Pritam Singh Kavi'],
        'refs' => [],
    ],

    'biographies' => [
        'label' => 'Biographies',
        'blurb' => 'Lives of the Gurus, saints and modern figures.',
        'intro' => 'Lives of the Gurus and saints, of the martyrs and freedom fighters, and '
            . 'of the twentieth-century poets and scholars whose work is collected here.',
        'terms' => ['ਜੀਵਨੀ', 'ਜੀਵਨੀਚਰਿਤ੍ਰ', 'ਪੜਾਈ', 'ਜੀਵਨ ਦੀ',
                    'biography', 'biographies', 'life of', 'jeevani'],
        'related' => ['saints', 'history', 'poetry', 'martyrs'],
        'people' => ['Jagjit Singh Dr.', 'Nihal Singh Master', 'Santokh Singh Namdhari'],
        'refs' => [[$w . 'Sikh_Gurus', 'The ten Gurus'], [$w . 'Sikh_history', 'Sikh history']],
    ],

    'learning' => [
        'label' => 'Learning Punjabi',
        'blurb' => 'Grammar, phrasebooks and courses.',
        'intro' => 'Learning the language: grammar, phonetics, phrasebooks and primers. '
            . 'NOTE: too few holdings to render as a section yet.',
        'terms' => ['ਪੰਜਾਬੀ ਸਿੱਖੋ', 'ਵਿਆਕਰਨ', 'ਪੜ੍ਹਾਈ ਸਹਾਇਤਾ',
                    'learn punjabi', 'learning punjabi', 'grammar', 'vyakaran', 'phrasebook', 'course'],
        'related' => ['dictionaries', 'children', 'gurmukhi'],
        'people' => [],
        'refs' => [[$w . 'Punjabi_University', 'Punjabi University, Patiala']],
    ],

    ];
}
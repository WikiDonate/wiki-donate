// ✅ Helper to parse [[User:username|username]] style wiki links
export const parseWikiUserLinks = (html) => {
    if (!html) return html
    return html.replace(
        /\[\[User:([^\|\]]+)\|([^\]]+)\]\]/g,
        `<a href="/user/page?username=$1" class="text-indigo-600 font-semibold hover:text-purple-600">$2</a> • `
    )
}

<tr data-row>
    <td><input type="hidden" name="variants[{{ $i }}][id]" value="{{ $v['id'] ?? '' }}"><input name="variants[{{ $i }}][options]" value="{{ $v['options'] ?? '' }}" placeholder="Size=A4; Frame=Black" data-variant-options></td>
    <td><input type="number" step="0.01" min="0" name="variants[{{ $i }}][price]" value="{{ $v['price'] ?? '' }}" class="w-num"></td>
    <td><input type="number" step="0.01" min="0" name="variants[{{ $i }}][sale_price]" value="{{ $v['sale_price'] ?? '' }}" class="w-num"></td>
    <td><input name="variants[{{ $i }}][sku]" value="{{ $v['sku'] ?? '' }}" class="w-sku"></td>
    <td class="center"><input type="checkbox" name="variants[{{ $i }}][in_stock]" value="1" @checked(! empty($v['in_stock']))></td>
    <td><input type="number" min="0" step="1" name="variants[{{ $i }}][stock_qty]" value="{{ $v['stock_qty'] ?? '' }}" class="w-num" placeholder="∞" title="Blank = made to order"></td>
    <td class="v-img">
        <input type="hidden" name="variants[{{ $i }}][image]" value="{{ $v['image'] ?? '' }}">
        <img data-preview-for="variants[{{ $i }}][image]" src="{{ ! empty($v['image']) ? media_url($v['image']) : '' }}" alt="" @if (empty($v['image'])) hidden @endif>
        <button type="button" class="link" data-media-pick data-multiple="0" data-folder="products" data-input="variants[{{ $i }}][image]">Choose</button>
        <button type="button" class="link danger" data-media-clear data-input="variants[{{ $i }}][image]" title="Remove image">✕</button>
    </td>
    <td><button type="button" class="link danger" data-remove-row>✕</button></td>
</tr>
